<?php
/**
 * Stáhne jednorázové dary brněnského fondu z dary.zeleni.cz a nové z nich
 * vloží do Action Networku (aby s dárci šlo komunikovat). Běh přes cron.
 *
 * Logika výběru:
 *   - jen jednorázové platby fondu (payment.periodical=false),
 *   - od data config['onetime_since'] (default 1. 1. 2026),
 *   - částka >= config['onetime_min_amount'] (default 30 Kč),
 *   - vyřazení testovacích záznamů (jméno/příjmení/firma/e-mail obsahuje "test"),
 *   - musí mít e-mail,
 *   - status libovolný (včetně 'promised' — převody se u fondu nepárují).
 *
 * Deduplikace: tabulka onetime_synced v donors.db (payment_id = _id z dary API).
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
        http_response_code(403);
        echo "Forbidden\n";
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

// ── 2) Stažení jednorázových plateb fondu ───────────────────────────────────
function dary_fetch_onetime(array $config, string $token): array {
    $url = rtrim($config['dary_api_base'], '/') . '/api/payments?'
        . http_build_query([
            'payment.fund'       => $config['dary_fund_id'],
            'payment.periodical' => 'false',
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

// ── 4) Vložení dárce do Action Networku (bez autoresponse) ──────────────────
function push_to_an(array $config, array $p): bool {
    $donor = is_array($p['donor'] ?? null) ? $p['donor'] : [];
    $email = trim((string)($donor['email'] ?? ''));
    $amount = (int)($p['amount'] ?? 0);

    $person = [
        'given_name'     => (string)($donor['name'] ?? ''),
        'family_name'    => (string)($donor['surname'] ?? ''),
        'email_addresses'=> [['address' => $email]],
        'custom_fields'  => array_filter([
            'datum_narozeni'    => (string)($donor['birth'] ?? ''),
            'castka_daru'       => $amount,
            'zpusob_platby'     => 'jednorazovy_dar',
            'variabilni_symbol' => (string)($p['vs'] ?? ''),
        ], fn($v) => $v !== '' && $v !== null),
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

    $body = [
        'person'    => $person,
        'add_tags'  => [$config['an_tag_onetime']],
        // Potvrzení posílá dary.zeleni.cz → autoresponse z AN by byla duplicita.
        'triggers'  => ['autoresponse' => ['enabled' => false]],
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

// ── Běh ──────────────────────────────────────────────────────────────────────
try {
    $pdo = donor_db();

    out(($dryRun ? '[DRY-RUN] ' : '') . 'Přihlašuji se k dary.zeleni.cz…');
    $token = dary_login($config);

    out('Stahuji jednorázové platby fondu…');
    $payments = dary_fetch_onetime($config, $token);
    out('Staženo plateb: ' . count($payments));

    $eligible = array_values(array_filter($payments, fn($p) => is_eligible($p, $config)));
    out('Způsobilých po filtru: ' . count($eligible));

    $checkStmt  = $pdo->prepare('SELECT 1 FROM onetime_synced WHERE payment_id = ?');
    $insertStmt = $pdo->prepare(
        'INSERT INTO onetime_synced
            (payment_id, dary_created_at, donor_name, donor_surname, donor_email,
             donor_phone, donor_city, amount, vs, status)
         VALUES (:pid, :created, :name, :surname, :email, :phone, :city, :amount, :vs, :status)'
    );

    $sent = 0; $skipped = 0; $failed = 0;
    foreach ($eligible as $p) {
        $pid = (string)($p['_id'] ?? '');
        if ($pid === '') { continue; }

        $checkStmt->execute([$pid]);
        if ($checkStmt->fetchColumn()) { $skipped++; continue; } // už odesláno

        $donor = is_array($p['donor'] ?? null) ? $p['donor'] : [];
        $email = trim((string)($donor['email'] ?? ''));
        $name  = trim(((string)($donor['name'] ?? '')) . ' ' . ((string)($donor['surname'] ?? '')));

        if ($dryRun) {
            out("  [by se poslalo] $name <$email> · " . (int)($p['amount'] ?? 0) . ' Kč · VS ' . ($p['vs'] ?? '—'));
            $sent++;
            continue;
        }

        if (push_to_an($config, $p)) {
            $insertStmt->execute([
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

    out('');
    out(sprintf(
        '%sHotovo. %s: %d, přeskočeno (už odesláno): %d, chyb: %d.',
        $dryRun ? '[DRY-RUN] ' : '',
        $dryRun ? 'k odeslání' : 'odesláno',
        $sent, $skipped, $failed
    ));
} catch (Throwable $e) {
    http_response_code($isCli ? 1 : 500);
    error_log('sync-onetime error: ' . $e->getMessage());
    out('CHYBA: ' . $e->getMessage());
    exit(1);
}
