<?php
/**
 * Přepočte /stav.json ze /data/mc.json + /data/dary.jsonl od nuly, bez
 * přidání nového daru. Použití:
 *
 *   - po prvním nasazení /data/mc.json + zárodečného /data/dary.jsonl,
 *     kdy ještě žádný dar přes web neprošel a stav.json tedy neexistuje,
 *   - pro ruční opravu, kdyby stav.json z nějakého důvodu nesedal s logem.
 *
 * Spuštění:
 *   CLI:  php rebuild-stav.php
 *   HTTP: GET /rebuild-stav.php?token=<ADMIN_PASSWORD>
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

try {
    if (!is_dir(mc_data_dir())) {
        mkdir(mc_data_dir(), 0755, true);
    }
    $fp = fopen(mc_lock_path(), 'c');
    flock($fp, LOCK_EX);
    $stav = mc_recompute_stav();
    flock($fp, LOCK_UN);
    fclose($fp);
    $msg = sprintf(
        "Hotovo. Celkem vybráno %d Kč z %d Kč, %d darů.\n",
        $stav['celkem']['vybrano'],
        $stav['celkem']['cil'],
        $stav['celkem']['darcu']
    );
    echo $msg;
} catch (Throwable $e) {
    http_response_code($isCli ? 1 : 500);
    error_log('rebuild-stav error: ' . $e->getMessage());
    echo 'CHYBA: ' . $e->getMessage() . "\n";
    exit(1);
}
