<?php
/**
 * Šablona pro config.php. Při FTP deployi GitHub Actions tento soubor zkopíruje
 * jako config.php a placeholdery (__VARIABLE__) nahradí hodnotami z GitHub Secrets.
 *
 * Tento template soubor je v gitu, výsledný config.php nikoli (viz .gitignore).
 */

return [
    // Stripe — z Dashboard → Developers → API keys (účet lepsibrno.cz)
    'stripe_secret_key'     => '__STRIPE_SECRET_KEY__',
    // Webhook signing secret — z Dashboard → Developers → Webhooks → tvůj endpoint
    'stripe_webhook_secret' => '__STRIPE_WEBHOOK_SECRET__',

    // Povolené měsíční částky (Kč). Bránka odmítne cokoli jiného (ochrana proti podvržení).
    'allowed_amounts'       => [199, 339, 599],

    // Návratové URL po platbě / zrušení
    'success_url'           => 'https://lepsibrno.cz/dekujeme.html?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url'            => 'https://lepsibrno.cz/#predplatit',

    // Heslo do administrace (stránka /transakce.php). Nastav přes GitHub Secret ADMIN_PASSWORD.
    'admin_password'        => '__ADMIN_PASSWORD__',

    // Action Network — server-to-server přes API token (Group → API & Sync → API key)
    'an_api_token'          => '__AN_API_TOKEN__',
    'an_url'                => 'https://actionnetwork.org/api/v2/forms/bc501b67-c587-494a-bf1e-570a9f73e8f5/submissions/',
    'an_tag'                => 'lepsibrno',
    // Tag pro jednorázové dárce stažené z dary.zeleni.cz (sync-onetime.php)
    'an_tag_onetime'        => 'lepsibrno_jednorazovy',

    // dary.zeleni.cz — admin API pro stahování jednorázových darů (sync-onetime.php)
    'dary_api_base'         => 'https://api.dary.zeleni.cz',
    'dary_username'         => '__DARY_USERNAME__',
    'dary_password'         => '__DARY_PASSWORD__',
    'dary_fund_id'          => '57e111cd859b5a092c8c7c1a',
    // Filtry pro sync: jen dary od tohoto data a od této částky (Kč). Vyloučí i testy.
    'onetime_since'         => '2026-01-01',
    'onetime_min_amount'    => 30,

    // Děkovný e-mail
    'mail_from'             => 'kampan@zelenebrno.cz',
    'mail_from_name'        => 'Zelení Brno',
    'mail_subject'          => 'Děkujeme za tvé předplatné Lepšího Brna! 💚',

    // Volební den — pro výpočet počtu měsíčních plateb do voleb
    'election_date'         => '2026-10-10',
    // Zachováno pro zpětnou kompatibilitu; nepoužívá se v nové logice
    'campaign_end'          => '2026-10-10',
];
