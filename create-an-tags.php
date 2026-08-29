<?php
/**
 * Jednorázově založí v Action Networku tagy potřebné pro kampaň na čtvrti
 * (index.html) — společný "brno-2026-noviny" + "brno-2026-noviny-<čtvrť>"
 * pro každou čtvrť z mc.json.
 *
 * DŮLEŽITÉ: tag v AN musí existovat PŘED prvním použitím v add_tags.
 * Helpery (person_signup_helper apod.) neexistující tag tiše ignorují —
 * vrátí 200 OK, ale tag se nepřiřadí a nezaloží, bez jakékoli chyby. Proto
 * tenhle skript pouští ručně/jednorázově PŘED prvním darem na čtvrť.
 *
 * POST na /api/v2/tags/ je deduplikovaný podle jména (case-insensitive),
 * takže je bezpečné skript spustit i opakovaně — existující tagy se jen
 * přeskočí, nic se nepřepíše ani nezdvojí.
 *
 * Spuštění:
 *   CLI:  php create-an-tags.php
 *   HTTP: GET /create-an-tags.php?token=<ADMIN_PASSWORD>
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mc-store.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $adminPassword = (string)($config['admin_password'] ?? '');
    $token = (string)($_GET['token'] ?? '');
    if ($adminPassword === '' || !hash_equals($adminPassword, $token)) {
        http_response_code(403);
        echo "Chybí platný token.\n";
        exit;
    }
}

function out(string $msg): void {
    echo $msg . "\n";
    @flush();
}

$apiToken = (string)($config['an_api_token'] ?? '');
if ($apiToken === '') {
    out('CHYBA: an_api_token není v config.php nastavený.');
    exit(1);
}

/** Založí tag v AN (group API token, ne osobní). Vrátí true, i když už existoval. */
function create_an_tag(string $name, string $apiToken): bool {
    $ch = curl_init('https://actionnetwork.org/api/v2/tags/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['name' => $name], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'OSDI-API-Token: ' . $apiToken,
        ],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        out("  ✗ $name — cURL chyba: $err");
        return false;
    }
    if ($status >= 300) {
        out("  ✗ $name — HTTP $status: $resp");
        return false;
    }
    out("  ✓ $name");
    return true;
}

try {
    $mcConfig = mc_load_config();
} catch (Throwable $e) {
    out('CHYBA: mc.json se nepodařilo načíst — ' . $e->getMessage());
    exit(1);
}

// Společný tag + tag každé čtvrti — přesně hodnoty z mc.json (an_tag),
// stejné jako počítá klient (index.html) i stripe-webhook.php.
$tags = ['brno-2026-noviny'];
foreach ($mcConfig as $d) {
    $tag = trim((string)($d['an_tag'] ?? ''));
    if ($tag !== '') $tags[] = $tag;
}

out('Zakládám ' . count($tags) . ' tagů v Action Networku…');
$ok = 0;
$fail = 0;
foreach ($tags as $tag) {
    if (create_an_tag($tag, $apiToken)) {
        $ok++;
    } else {
        $fail++;
    }
}
out("Hotovo. OK: $ok, chyb: $fail.");
if ($fail > 0) {
    http_response_code($isCli ? 1 : 500);
    exit(1);
}
