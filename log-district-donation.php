<?php
/**
 * Zapíše jednorázový dar na konkrétní čtvrť (stránka ctvrte.html) do
 * /data/dary.jsonl (přepočte /stav.json) a zároveň do admin evidence
 * district_donations (SQLite, viz db.php) — ta drží i osobní údaje dárce,
 * ať jde transakci dohledat v transakce.php. dary.jsonl žádné osobní
 * údaje nemá, je to veřejně čitelný zdroj pro progress bary.
 *
 * Volá se z frontendu u platby PŘEVODEM hned, bez čekání na odpověď.
 * Action Network se volá až POTÉ, co frontend dostane VS z API
 * dary.zeleni.cz (aby tam mohl poslat i variabilní symbol) — dárce se
 * do AN zapíše, i kdyby se VS získat nepodařilo, jen bez něj.
 *
 * U platby KARTOU se sem nesahá vůbec — tam log i AN řeší až
 * stripe-webhook.php po potvrzení platby (viz tam).
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/mc-store.php';
require __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$mcKey  = (string)($input['mc'] ?? '');
$amount = (int)($input['amount'] ?? 0);
$method = (string)($input['method'] ?? '');
$donor  = is_array($input['donor'] ?? null) ? $input['donor'] : [];

if ($method !== 'prevod' && $method !== 'karta') {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatná metoda']);
    exit;
}
if ($amount < 50) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatná částka']);
    exit;
}

try {
    $config = mc_load_config();
} catch (Throwable $e) {
    error_log('log-district-donation config error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Konfigurace čtvrtí není dostupná']);
    exit;
}
if (!isset($config[$mcKey])) {
    http_response_code(400);
    echo json_encode(['error' => 'Neznámá čtvrť']);
    exit;
}

try {
    mc_append_donation($mcKey, $amount, $method);
} catch (Throwable $e) {
    error_log('log-district-donation write error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Zápis se nepodařil']);
    exit;
}

// Admin evidence — nekritická, chyba tady dar nezruší (do dary.jsonl už
// je zapsáno a stav.json přepočten).
record_district_donation([
    'mc'             => $mcKey,
    'mc_nazev'       => (string)($config[$mcKey]['nazev'] ?? $mcKey),
    'payment_method' => $method,
    'amount'         => $amount,
    'donor_name'     => $donor['name']    ?? null,
    'donor_surname'  => $donor['surname'] ?? null,
    'donor_email'    => $donor['email']   ?? null,
    'donor_phone'    => $donor['mobile']  ?? null,
    'donor_city'     => $donor['city']    ?? null,
]);

echo json_encode(['ok' => true]);
