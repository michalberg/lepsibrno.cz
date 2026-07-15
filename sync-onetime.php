<?php
/**
 * Stáhne dary brněnského fondu z dary.zeleni.cz a nové z nich vloží do
 * Action Networku (aby s dárci šlo komunikovat). Běh přes cron.
 *
 * Synchronizují se DVA typy:
 *
 *   A) JEDNORÁZOVÉ dary (payment.periodical=false) → tabulka onetime_synced,
 *      AN tag config['an_tag_onetime'], zpusob_platby='jednorazovy_dar'.
 *
 *   B) PRAVIDELNÉ dary založené PŘÍMO na dary.zeleni.cz (payment.periodical=true
 *      a periodicity='month') → tabulka recurring_synced, AN tag config['an_tag'],
 *      zpusob_platby='prevod'. Pravidelné dary zadané přes lepsibrno.cz mají
 *      periodicity='month'+ navíc 'monthly' (frontend posílá 'monthly') a už je
 *      máme v tabulce donors → vyloučíme je, ať nevznikají duplicity.
 *      Počítáme s fikcí, že pravidelný dar bude plněn (jednotlivé platby
 *      neřešíme), proto se dárce do AN pošle jen jednou (dedup dle payment_id)
 *      a do součtů „předplatného" přispěje amount * měsíce do voleb.
 *
 * Společné filtry způsobilosti:
 *   - od data config['onetime_since'] (default 1. 1. 2026),
 *   - částka >= config['onetime_min_amount'] (default 30 Kč),
 *   - vyřazení testovacích záznamů (jméno/příjmení/firma/e-mail obsahuje "test"),
 *   - musí mít e-mail,
 *   - status libovolný (včetně 'promised' — převody se u fondu nepárují).
 *
 * Deduplikace: payment_id = _id z dary API (onetime_synced / recurring_synced).
 * Do AN se posílá BEZ autoresponse (potvrzovací mail řeší dary.zeleni.cz).
 *
 * Spuštění:
 *   CLI:  php sync-onetime.php [--dry-run]
 *   HTTP: GET /sync-onetime.php?token=<ADMIN_PASSWORD>[&dry-run=1]   (pro cron přes wget)
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$isCli = (PHP_SAPI === 'cli');

// ── Přístup ────────────────────────────────────────────────────────────────
// Z webu jen s platným tokenem (= admin_password), z CLI vždy.
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $adminPassword = (string)($config['admin_password'] ?? '');
    $token = (string)($_GET['token'] ?? '');
    if ($adminPassword === '' || !hash_equals($adminPassword, $token)) {
        // Vrátíme 200 (ne 403), aby validátor cronu viděl, že skript existuje.
        // Bez platného tokenu se ale nic nestáhne ani neodešle.
        echo "OK\n";
        exit;
    }
}

$dryRun = $isCli
    ? in_array('--dry-run', $argv ?? [], true)
    : isset($_GET['dry-run']);

function out(string $msg): void {
    echo $msg . "\n";
    @flush();
}

// ── 1) Přihlášení do dary.zeleni.cz ─────────────────────────────────────────
function dary_login(array $config): string {
    $url = rtrim($config['dary_api_base'], '/') . '/api/auth/login';
    $payload = json_encode([
        'username' => $config['dary_username'],
        'password' => $config['dary_password'],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false || $status !== 200) {
        throw new RuntimeException("Přihlášení k dary.zeleni.cz selhalo (HTTP $status).");
    }
    $data = json_decode($resp, true);
    if (!is_array($data) || empty($data['token'])) {
        throw new RuntimeException('Odpověď loginu neobsahuje token.');
    }
    return (string)$data['token'];
}

// ── 2) Stažení plateb fondu (periodical=false|true) ─────────────────────────
function dary_fetch(array $config, string $token, bool $periodical): array {
    $url = rtrim($config['dary_api_base'], '/') . '/api/payments?'
        . http_build_query([
            'payment.fund'       => $config['dary_fund_id'],
            'payment.periodical' => $periodical ? 'true' : 'false',
        ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['x-access-token: ' . $token],
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false || $status !== 200) {
        throw new RuntimeException("Stažení plateb selhalo (HTTP $status).");
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException('Neočekávaná odpověď seznamu plateb.');
    }
    return $data;
}

// ── 3) Filtr způsobilosti ───────────────────────────────────────────────────
function is_eligible(array $p, array $config): bool {
    $created = (string)($p['createdAt'] ?? '');
    if ($created < $config['onetime_since']) return false;          // jen aktuální kampaň

    if ((int)($p['amount'] ?? 0) < (int)$config['onetime_min_amount']) return false;

    $donor = is_array($p['donor'] ?? null) ? $p['donor'] : [];
    $email = trim((string)($donor['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;   // bez e-mailu nelze do AN

    $blob = mb_strtolower(implode(' ', [
        $donor['name'] ?? '', $donor['surname'] ?? '', $donor['company'] ?? '', $email,
    ]));
    if (mb_strpos($blob, 'test') !== false) return false;           // vyřaď testy

    return true;
}

// Pravidelný dar je „náš" (z lepsibrno.cz), když frontend poslal periodicity
// 'monthly'. Přímo na dary.zeleni.cz/brno vzniká 'month'. Jen ty přímé chceme
// stahovat — ty z lepsibrno.cz už máme v tabulce donors.
function is_direct_recurring(array $p): bool {
    return (string)($p['periodicity'] ?? '') === 'month';
}

// Počet měsíčních plateb od data daru do voleb (stejná logika jako frontend:
// počítáme 1. dny v měsíci od měsíce po vzniku, resp. od téhož měsíce, vznikl-li
// dar 1., až do config['campaign_end']). Slouží k přepočtu amount → za kampaň.
function months_to_election(string $createdAt, array $config): int {
    try {
        $from = new DateTimeImmutable(substr($createdAt, 0, 10));
        $end  = new DateTimeImmutable((string)$config['campaign_end']);
    } catch (Throwable $e) {
        return 1;
    }
    $cursor = (int)$from->format('j') === 1
        ? $from->modify('first day of this month')
        : $from->modify('first day of next month');
    $count = 0;
    while ($cursor <= $end) {
        $count++;
        $cursor = $cursor->modify('first day of next month');
    }
    return max(1, $count);
}

// ── 4) Vložení dárce do Action Networku (bez autoresponse) ──────────────────
// Společný základ osoby (jméno, e-mail, telefon, adresa) sdílený oběma typy.
function an_base_person(array $donor, string $email): array {
    $person = [
        'given_name'      => (string)($donor['name'] ?? ''),
        'family_name'     => (string)($donor['surname'] ?? ''),
        'email_addresses' => [['address' => $email]],
    ];
    if (!empty($donor['mobile'])) {
        $person['phone_numbers'] = [['number' => (string)$donor['mobile']]];
    }
    if (!empty($donor['city']) || !empty($donor['address']) || !empty($donor['zip'])) {
        $person['postal_addresses'] = [array_filter([
            'address_lines' => !empty($donor['address']) ? [(string)$donor['address']] : null,
            'locality'      => (string)($donor['city'] ?? ''),
            'postal_code'   => (string)($donor['zip'] ?? ''),
            'country'       => 'CZ',
        ], fn($v) => $v !== '' && $v !== null && $v !== [])];
    }
    return $person;
}

function an_submit(array $config, array $person, string $tag, string $email): bool {
    $body = [
        'person'   => $person,
        'add_tags' => [$tag],
        // Potvrzení posílá dary.zeleni.cz → autoresponse z AN by byla duplicita.
        'triggers' => ['autoresponse' => ['enabled' => false]],
    ];

    $headers = ['Content-Type: application/json'];
    if (!empty($config['an_api_token'])) {
        $headers[] = 'OSDI-API-Token: ' . $config['an_api_token'];
    }

    $ch = curl_init($config['an_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($resp === false || $status < 200 || $status >= 300) {
        error_log("AN submit failed (HTTP $status) pro $email: " . (string)$resp);
        return false;
    }
    return true;
}

// Jednorázový dar → tag config['an_tag_onetime'], zpusob_platby='jednorazovy_dar'.
function push_onetime_to_an(array $config, array $p): bool {
    $donor  = is_array($p['donor'] ?? null) ? $p['donor'] : [];
    $email  = trim((string)($donor['email'] ?? ''));
    $amount = (int)($p['amount'] ?? 0);

    $person = an_base_person($donor, $email);
    $person['custom_fields'] = array_filter([
        'datum_narozeni'    => (string)($donor['birth'] ?? ''),
        'castka_daru'       => $amount,
        'zpusob_platby'     => 'jednorazovy_dar',
        'variabilni_symbol' => (string)($p['vs'] ?? ''),
    ], fn($v) => $v !== '' && $v !== null);

    return an_submit($config, $person, $config['an_tag_onetime'], $email);
}

// Pravidelný dar → tag config['an_tag'] (jako předplatitelé z lepsibrno.cz),
// stejná custom_fields jako převod z webu (mesicni_castka, mesicu_do_voleb…).
function push_recurring_to_an(array $config, array $p, int $monthsLeft): bool {
    $donor  = is_array($p['donor'] ?? null) ? $p['donor'] : [];
    $email  = trim((string)($donor['email'] ?? ''));
    $amount = (int)($p['amount'] ?? 0);

    $person = an_base_person($donor, $email);
    $person['custom_fields'] = array_filter([
        'datum_narozeni'    => (string)($donor['birth'] ?? ''),
        'mesicni_castka'    => $amount,
        'mesicu_do_voleb'   => $monthsLeft,
        'celkem_za_kampan'  => $amount * $monthsLeft,
        'zpusob_platby'     => 'prevod',
        'variabilni_symbol' => (string)($p['vs'] ?? ''),
    ], fn($v) => $v !== '' && $v !== null);

    return an_submit($config, $person, $config['an_tag'], $email);
}

// ── Běh ──────────────────────────────────────────────────────────────────────
try {
    $pdo = donor_db();

    out(($dryRun ? '[DRY-RUN] ' : '') . 'Přihlašuji se k dary.zeleni.cz…');
    $token = dary_login($config);

    // ── A) JEDNORÁZOVÉ dary ─────────────────────────────────────────────────
    out('Stahuji jednorázové platby fondu…');
    $onetime = dary_fetch($config, $token, false);
    out('Staženo jednorázových: ' . count($onetime));

    $eligible = array_values(array_filter($onetime, fn($p) => is_eligible($p, $config)));
    out('Způsobilých po filtru: ' . count($eligible));

    $checkOnetime  = $pdo->prepare('SELECT 1 FROM onetime_synced WHERE payment_id = ?');
    $insertOnetime = $pdo->prepare(
        'INSERT INTO onetime_synced
            (payment_id, dary_created_at, donor_name, donor_surname, donor_email,
             donor_phone, donor_city, amount, vs, status)
         VALUES (:pid, :created, :name, :surname, :email, :phone, :city, :amount, :vs, :status)'
    );

    $sent = 0; $skipped = 0; $failed = 0;
    foreach ($eligible as $p) {
        $pid = (string)($p['_id'] ?? '');
        if ($pid === '') { continue; }

        $checkOnetime->execute([$pid]);
        if ($checkOnetime->fetchColumn()) { $skipped++; continue; } // už odesláno

        $donor = is_array($p['donor'] ?? null) ? $p['donor'] : [];
        $email = trim((string)($donor['email'] ?? ''));
        $name  = trim(((string)($donor['name'] ?? '')) . ' ' . ((string)($donor['surname'] ?? '')));

        if ($dryRun) {
            out("  [by se poslalo] $name <$email> · " . (int)($p['amount'] ?? 0) . ' Kč · VS ' . ($p['vs'] ?? '—'));
            $sent++;
            continue;
        }

        if (push_onetime_to_an($config, $p)) {
            $insertOnetime->execute([
                ':pid'     => $pid,
                ':created' => (string)($p['createdAt'] ?? ''),
                ':name'    => (string)($donor['name'] ?? ''),
                ':surname' => (string)($donor['surname'] ?? ''),
                ':email'   => $email,
                ':phone'   => (string)($donor['mobile'] ?? ''),
                ':city'    => (string)($donor['city'] ?? ''),
                ':amount'  => (int)($p['amount'] ?? 0),
                ':vs'      => (string)($p['vs'] ?? ''),
                ':status'  => (string)($p['status'] ?? ''),
            ]);
            out("  ✓ odesláno: $name <$email>");
            $sent++;
        } else {
            out("  ✗ chyba: $name <$email>");
            $failed++;
        }
    }

    // ── B) PRAVIDELNÉ dary založené přímo na dary.zeleni.cz ──────────────────
    out('');
    out('Stahuji pravidelné platby fondu…');
    $periodical = dary_fetch($config, $token, true);
    out('Staženo pravidelných: ' . count($periodical));

    // Jen přímé (periodicity='month') — ty z lepsibrno.cz ('monthly') už máme.
    $recEligible = array_values(array_filter(
        $periodical,
        fn($p) => is_eligible($p, $config) && is_direct_recurring($p)
    ));
    out('Pravidelných mimo lepsibrno.cz po filtru: ' . count($recEligible));

    $checkRecurring  = $pdo->prepare('SELECT 1 FROM recurring_synced WHERE payment_id = ?');
    $insertRecurring = $pdo->prepare(
        'INSERT INTO recurring_synced
            (payment_id, dary_created_at, donor_name, donor_surname, donor_email,
             donor_phone, donor_city, amount, months_left, total_campaign, vs, status)
         VALUES (:pid, :created, :name, :surname, :email, :phone, :city, :amount,
                 :months, :campaign, :vs, :status)'
    );

    $recSent = 0; $recSkipped = 0; $recFailed = 0;
    foreach ($recEligible as $p) {
        $pid = (string)($p['_id'] ?? '');
        if ($pid === '') { continue; }

        $checkRecurring->execute([$pid]);
        if ($checkRecurring->fetchColumn()) { $recSkipped++; continue; } // už odesláno

        $donor   = is_array($p['donor'] ?? null) ? $p['donor'] : [];
        $email   = trim((string)($donor['email'] ?? ''));
        $name    = trim(((string)($donor['name'] ?? '')) . ' ' . ((string)($donor['surname'] ?? '')));
        $amount  = (int)($p['amount'] ?? 0);
        $months  = months_to_election((string)($p['createdAt'] ?? ''), $config);

        if ($dryRun) {
            out("  [by se poslalo] $name <$email> · $amount Kč/měs × $months měs = "
                . ($amount * $months) . ' Kč · VS ' . ($p['vs'] ?? '—'));
            $recSent++;
            continue;
        }

        if (push_recurring_to_an($config, $p, $months)) {
            $insertRecurring->execute([
                ':pid'      => $pid,
                ':created'  => (string)($p['createdAt'] ?? ''),
                ':name'     => (string)($donor['name'] ?? ''),
                ':surname'  => (string)($donor['surname'] ?? ''),
                ':email'    => $email,
                ':phone'    => (string)($donor['mobile'] ?? ''),
                ':city'     => (string)($donor['city'] ?? ''),
                ':amount'   => $amount,
                ':months'   => $months,
                ':campaign' => $amount * $months,
                ':vs'       => (string)($p['vs'] ?? ''),
                ':status'   => (string)($p['status'] ?? ''),
            ]);
            out("  ✓ odesláno: $name <$email>");
            $recSent++;
        } else {
            out("  ✗ chyba: $name <$email>");
            $recFailed++;
        }
    }

    out('');
    out(sprintf(
        '%sHotovo. Jednorázové — %s: %d, přeskočeno: %d, chyb: %d.',
        $dryRun ? '[DRY-RUN] ' : '',
        $dryRun ? 'k odeslání' : 'odesláno',
        $sent, $skipped, $failed
    ));
    out(sprintf(
        'Pravidelné — %s: %d, přeskočeno: %d, chyb: %d.',
        $dryRun ? 'k odeslání' : 'odesláno',
        $recSent, $recSkipped, $recFailed
    ));

    // ── C) Přepočet shody s telefundraisingem (transakce.php) ────────────────
    // Vždy (i v dry-run), ať ikonky/zdroj na transakce.php sedí i bez odesílání
    // do AN. Neběží při každém načtení té stránky — jen tady, jednou denně.
    out('');
    out('Přepočítávám shodu s telefundraisingem…');
    refresh_telefundraising_matches();
    out('Hotovo.');
} catch (Throwable $e) {
    http_response_code($isCli ? 1 : 500);
    error_log('sync-onetime error: ' . $e->getMessage());
    out('CHYBA: ' . $e->getMessage());
    exit(1);
}
