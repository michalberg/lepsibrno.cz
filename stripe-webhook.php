<?php
/**
 * Stripe webhook. Stripe sem pošle událost po dokončení platby.
 * Při `checkout.session.completed`:
 *   1) ověří podpis (HMAC-SHA256 s webhook secretem),
 *   2) z metadat session zapíše dárce do Action Networku (vč. UTM),
 *   3) odešle dárci děkovný e-mail.
 *
 * Endpoint nastav v Stripe Dashboard → Developers → Webhooks, událost
 * `checkout.session.completed`. Signing secret patří do config.php.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$payload   = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

/** Ověření podpisu podle Stripe schématu `t=...,v1=...`. */
function verify_stripe_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    if ($secret === '' || $header === '') return false;
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        if ($k === 't') $timestamp = $v;
        if ($k === 'v1') $signatures[] = $v;
    }
    if ($timestamp === null || empty($signatures)) return false;
    if (abs(time() - (int)$timestamp) > $tolerance) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

if (!verify_stripe_signature($payload, $sigHeader, $config['stripe_webhook_secret'])) {
    http_response_code(400);
    error_log('Stripe webhook: neplatný podpis');
    exit('Invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    exit('Invalid payload');
}

// Reagujeme jen na dokončený checkout. Ostatní události potvrdíme 200, ať Stripe neretřuje.
if (($event['type'] ?? '') !== 'checkout.session.completed') {
    http_response_code(200);
    exit('Ignored');
}

$session = $event['data']['object'] ?? [];
$m       = $session['metadata'] ?? [];
$amount  = (int)($m['amount'] ?? 0);
$email   = (string)($m['donor_email'] ?? '');

// Měsíce do konce kampaně — pro "celkem za kampaň".
$monthsLeft = 1;
try {
    $now = new DateTime('today');
    $end = new DateTime($config['campaign_end']);
    if ($end > $now) {
        $diff = $now->diff($end);
        $monthsLeft = max(1, $diff->y * 12 + $diff->m + ($diff->d > 0 ? 1 : 0));
    }
} catch (Exception $e) { /* ponecháme 1 */ }

// --- 0) Lokální evidence dárce ----------------------------------------------
record_donor([
    'payment_method'         => 'card',
    'status'                 => 'paid',
    'donor_name'             => $m['donor_name'] ?? null,
    'donor_surname'          => $m['donor_surname'] ?? null,
    'donor_birth'            => $m['donor_birth'] ?? null,
    'donor_email'            => $email ?: null,
    'donor_phone'            => $m['donor_phone'] ?? null,
    'donor_address'          => $m['donor_address'] ?? null,
    'donor_city'             => $m['donor_city'] ?? null,
    'donor_zip'              => $m['donor_zip'] ?? null,
    'amount'                 => $amount ?: null,
    'months_left'            => $monthsLeft,
    'total_campaign'         => $amount * $monthsLeft,
    'utm_source'             => $m['utm_source'] ?? null,
    'utm_medium'             => $m['utm_medium'] ?? null,
    'utm_campaign'           => $m['utm_campaign'] ?? null,
    'utm_content'            => $m['utm_content'] ?? null,
    'utm_term'               => $m['utm_term'] ?? null,
    'referrer'               => $m['referrer'] ?? null,
    'landing_page'           => $m['landing_page'] ?? null,
    'stripe_session_id'      => $session['id'] ?? null,
    'stripe_subscription_id' => $session['subscription'] ?? null,
]);

// --- 1) Action Network -------------------------------------------------------
if ($config['an_api_token'] !== '' && $email !== '') {
    $anBody = [
        'person' => [
            'given_name'      => $m['donor_name'] ?? '',
            'family_name'     => $m['donor_surname'] ?? '',
            'email_addresses' => [['address' => $email]],
            'postal_addresses' => [[
                'address_lines' => array_filter([$m['donor_address'] ?? '']),
                'locality'      => $m['donor_city'] ?? '',
                'postal_code'   => $m['donor_zip'] ?? '',
                'country'       => 'CZ',
            ]],
            'custom_fields' => [
                'datum_narozeni'  => $m['donor_birth'] ?? '',
                'mesicni_castka'  => $amount,
                'mesicu_do_voleb' => $monthsLeft,
                'celkem_za_kampan'=> $amount * $monthsLeft,
                'zpusob_platby'   => 'karta',
                'utm_source'      => $m['utm_source'] ?? '',
                'utm_medium'      => $m['utm_medium'] ?? '',
                'utm_campaign'    => $m['utm_campaign'] ?? '',
                'utm_content'     => $m['utm_content'] ?? '',
                'utm_term'        => $m['utm_term'] ?? '',
            ],
        ],
        'action_network:referrer_data' => [
            'source'   => ($m['utm_source'] ?? '') ?: 'brnoflix',
            'referrer' => $m['referrer'] ?? '',
            'website'  => $m['landing_page'] ?? '',
        ],
        'add_tags' => [$config['an_tag']],
    ];
    if (!empty($m['donor_phone'])) {
        $anBody['person']['phone_numbers'] = [['number' => $m['donor_phone']]];
    }

    $ch = curl_init($config['an_url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($anBody, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'OSDI-API-Token: ' . $config['an_api_token'],
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $anResp   = curl_exec($ch);
    $anStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($anStatus >= 400) {
        error_log('Action Network error (' . $anStatus . '): ' . $anResp);
    }
}

// --- 2) Děkovný e-mail -------------------------------------------------------
if ($email !== '') {
    $jmeno   = trim(($m['donor_name'] ?? '') . ' ' . ($m['donor_surname'] ?? ''));
    $celkem  = number_format($amount * $monthsLeft, 0, ',', ' ');
    $castka  = number_format($amount, 0, ',', ' ');

    $body  = "Ahoj " . ($m['donor_name'] ?? '') . ",\n\n";
    $body .= "moc děkujeme! Tvé měsíční předplatné " . $castka . " Kč jsme úspěšně nastavili.\n";
    $body .= "Do voleb to dělá zhruba " . $celkem . " Kč na lepší Brno.\n\n";
    $body .= "Platba běží přes Stripe a strhne se každý měsíc automaticky. Spravovat\n";
    $body .= "nebo zrušit ji můžeš kdykoli přes odkaz ve svém potvrzení od Stripe.\n\n";
    $body .= "Díky, že jsi v tom s námi. 💚\n";
    $body .= $config['mail_from_name'] . "\n";

    $headers  = 'From: ' . $config['mail_from_name'] . ' <' . $config['mail_from'] . ">\r\n";
    $headers .= 'Reply-To: ' . $config['mail_from'] . "\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";

    $subject = '=?UTF-8?B?' . base64_encode($config['mail_subject']) . '?=';

    if (!@mail($email, $subject, $body, $headers)) {
        error_log('Děkovný e-mail se nepodařilo odeslat: ' . $email);
    }
}

http_response_code(200);
echo 'OK';
