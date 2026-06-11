<?php
/**
 * Zapíše dárce s platbou převodem do lokální SQLite evidence.
 * Status = 'intent' — uživatel vyplnil formulář, jestli reálně pošle trvalý
 * příkaz se z naší strany nedá zjistit.
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

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

$donor    = is_array($input['donor'] ?? null) ? $input['donor'] : [];
$tracking = is_array($input['tracking'] ?? null) ? $input['tracking'] : [];
$amount   = (int)($input['amount'] ?? 0);
$months   = (int)($input['months_left'] ?? 0);

$id = record_donor([
    'payment_method'  => 'transfer',
    'status'          => 'intent',
    'donor_name'      => $donor['name']    ?? null,
    'donor_surname'   => $donor['surname'] ?? null,
    'donor_birth'     => $donor['birth']   ?? null,
    'donor_email'     => $donor['email']   ?? null,
    'donor_phone'     => $donor['mobile']  ?? null,
    'donor_address'   => $donor['address'] ?? null,
    'donor_city'      => $donor['city']    ?? null,
    'donor_zip'       => $donor['zip']     ?? null,
    'amount'          => $amount ?: null,
    'months_left'     => $months ?: null,
    'total_campaign'  => $amount && $months ? $amount * $months : null,
    'utm_source'      => $tracking['utm_source']   ?? null,
    'utm_medium'      => $tracking['utm_medium']   ?? null,
    'utm_campaign'    => $tracking['utm_campaign'] ?? null,
    'utm_content'     => $tracking['utm_content']  ?? null,
    'utm_term'        => $tracking['utm_term']     ?? null,
    'referrer'        => $tracking['referrer']     ?? null,
    'landing_page'    => $tracking['landing_page'] ?? null,
    'variable_symbol' => (string)($input['variable_symbol'] ?? '') ?: null,
]);

if ($id === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Save failed']);
    exit;
}

echo json_encode(['ok' => true, 'id' => $id]);
