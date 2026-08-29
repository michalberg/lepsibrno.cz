<?php
/**
 * Vytvoří Stripe Checkout Session pro JEDNORÁZOVÝ dar na inzerci v jedné
 * čtvrti (stránka ctvrte.html) a vrátí platební URL.
 *
 * Na rozdíl od create-checkout.php (měsíční předplatné) je mode='payment',
 * částka je volná (ne z pevného seznamu) a metadata nesou čtvrť (mc + její
 * an_tag z mc.json). Zápis do dary.jsonl a Action Network řeší až
 * stripe-webhook.php po potvrzení platby — tady se nic nezapisuje.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mc-store.php';

function fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Method not allowed');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    fail(400, 'Neplatný požadavek');
}

$amount = (int)($input['amount'] ?? 0);
if ($amount < 50 || $amount > 1000000) {
    fail(400, 'Nepovolená částka');
}

$mcKey = (string)($input['mc'] ?? '');
try {
    $mcConfig = mc_load_config();
} catch (Throwable $e) {
    error_log('create-district-checkout config error: ' . $e->getMessage());
    fail(500, 'Konfigurace čtvrtí není dostupná');
}
if (!isset($mcConfig[$mcKey])) {
    fail(400, 'Neznámá čtvrť');
}
$mcNazev = (string)($mcConfig[$mcKey]['nazev'] ?? $mcKey);
$mcAnTag = (string)($mcConfig[$mcKey]['an_tag'] ?? '');

$donor    = is_array($input['donor'] ?? null) ? $input['donor'] : [];
$tracking = is_array($input['tracking'] ?? null) ? $input['tracking'] : [];

$email = trim((string)($donor['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, 'Neplatný e-mail');
}

// Metadata — přečte je stripe-webhook.php po zaplacení. Přítomnost klíče
// "mc" tam rozliší jednorázový dar na čtvrť od měsíčního předplatného.
$metadata = [
    'mc'            => $mcKey,
    'mc_nazev'      => $mcNazev,
    'mc_an_tag'     => $mcAnTag,
    'donor_name'    => (string)($donor['name'] ?? ''),
    'donor_surname' => (string)($donor['surname'] ?? ''),
    'donor_birth'   => (string)($donor['birth'] ?? ''),
    'donor_email'   => $email,
    'donor_phone'   => (string)($donor['mobile'] ?? ''),
    'donor_address' => (string)($donor['address'] ?? ''),
    'donor_city'    => (string)($donor['city'] ?? ''),
    'donor_zip'     => (string)($donor['zip'] ?? ''),
    'amount'        => (string)$amount,
    'utm_source'    => (string)($tracking['utm_source'] ?? ''),
    'utm_medium'    => (string)($tracking['utm_medium'] ?? ''),
    'utm_campaign'  => (string)($tracking['utm_campaign'] ?? ''),
    'utm_content'   => (string)($tracking['utm_content'] ?? ''),
    'utm_term'      => (string)($tracking['utm_term'] ?? ''),
    'referrer'      => (string)($tracking['referrer'] ?? ''),
    'landing_page'  => (string)($tracking['landing_page'] ?? ''),
];

$params = [
    'mode'                 => 'payment',
    'customer_email'       => $email,
    'payment_method_types' => ['card'],
    'success_url'          => $config['success_url_ctvrte'] ?? $config['success_url'],
    'cancel_url'           => $config['cancel_url_ctvrte'] ?? $config['cancel_url'],
    'line_items' => [[
        'quantity'   => 1,
        'price_data' => [
            'currency'     => 'czk',
            'unit_amount'  => $amount * 100, // v haléřích
            'product_data' => ['name' => 'Dar na inzerci v radničním zpravodaji – ' . $mcNazev],
        ],
    ]],
    'metadata' => $metadata,
];

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($params),
    CURLOPT_USERPWD        => $config['stripe_secret_key'] . ':',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 20,
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    error_log('Stripe cURL error: ' . $curlErr);
    fail(502, 'Nepodařilo se spojit s platební bránou');
}

$data = json_decode($response, true);
if ($status >= 400 || !isset($data['url'])) {
    error_log('Stripe API error (' . $status . '): ' . $response);
    fail(502, 'Platební bránu se nepodařilo inicializovat');
}

echo json_encode(['url' => $data['url']], JSON_UNESCAPED_UNICODE);
