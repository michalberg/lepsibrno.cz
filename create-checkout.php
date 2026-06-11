<?php
/**
 * Vytvoří Stripe Checkout Session pro měsíční předplatné (subscription) a vrátí
 * platební URL. Všechna data dárce + UTM se uloží do metadat session, odkud je
 * po zaplacení přečte stripe-webhook.php.
 *
 * Žádná externí knihovna není potřeba — voláme Stripe API přímo přes cURL.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

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
if (!in_array($amount, $config['allowed_amounts'], true)) {
    fail(400, 'Nepovolená částka');
}

$donor    = is_array($input['donor'] ?? null) ? $input['donor'] : [];
$tracking = is_array($input['tracking'] ?? null) ? $input['tracking'] : [];

$email = trim((string)($donor['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail(400, 'Neplatný e-mail');
}

// Metadata — pojedou do session i do subscription, ať je má webhook k dispozici.
// Stripe limit: max 50 klíčů, hodnota do 500 znaků.
$metadata = [
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
    'mode'                       => 'subscription',
    'customer_email'             => $email,
    'billing_address_collection' => 'required',
    'success_url'                => $config['success_url'],
    'cancel_url'                 => $config['cancel_url'],
    'line_items' => [[
        'quantity'   => 1,
        'price_data' => [
            'currency'     => 'czk',
            'unit_amount'  => $amount * 100, // v haléřích
            'recurring'    => ['interval' => 'month'],
            'product_data' => ['name' => 'Předplatné Lepší Brno – Dar Straně zelených (' . $amount . ' Kč/měsíc)'],
        ],
    ]],
    'metadata'          => $metadata,
    'subscription_data' => ['metadata' => $metadata],
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
