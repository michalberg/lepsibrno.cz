<?php
/**
 * Sdílená vrstva pro ukládání darů na jednotlivé městské části (kampaň
 * "radniční zpravodaje", stránka index.html).
 *
 * /data/ leží mimo webroot (o úroveň výš než tento soubor, stejně jako
 * donors.db v db.php) — git deploy na něj nikdy nesahá, takže se dary.jsonl
 * nikdy nepřepíše zpátky na zárodečná data. mc.json i počáteční dary.jsonl
 * se tam nahrávají ručně (viz server-data/ v repu).
 *
 *   /data/mc.json     – konfigurace čtrnácti čtvrtí, edituje se ručně
 *   /data/dary.jsonl  – log darů, jen se připisuje (JSON Lines)
 *   /data/.lock       – pomocný soubor pro zámek při zápisu
 *   /stav.json        – vypočtený souhrn ve webrootu, čte ho frontend
 */

declare(strict_types=1);

function mc_data_dir(): string {
    return __DIR__ . '/../data';
}

function mc_config_path(): string {
    return mc_data_dir() . '/mc.json';
}

function mc_log_path(): string {
    return mc_data_dir() . '/dary.jsonl';
}

function mc_lock_path(): string {
    return mc_data_dir() . '/.lock';
}

function mc_stav_path(): string {
    return __DIR__ . '/stav.json';
}

/** Konfigurace čtrnácti čtvrtí — pořadí klíčů určuje pořadí v tabulce. */
function mc_load_config(): array {
    $path = mc_config_path();
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("mc.json nejde načíst ($path)");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('mc.json obsahuje neplatný JSON');
    }
    return $data;
}

/**
 * Přečte celý log darů. Poškozený nebo neúplný řádek přeskočí, ať jeden
 * vadný zápis (např. useknutý při výpadku) nesloží celý přepočet.
 */
function mc_read_log(): array {
    $path = mc_log_path();
    if (!is_file($path)) return [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];
    $rows = [];
    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['mc'], $row['castka'])) {
            $rows[] = $row;
        }
    }
    return $rows;
}

/**
 * Přepočte stav ze mc.json + dary.jsonl a zapíše /stav.json atomicky (přes
 * dočasný soubor + rename), ať čtenář nikdy nedostane napůl zapsaný soubor.
 *
 * Volá se pod zámkem — buď z mc_append_donation, nebo samostatně při
 * bootstrapu / ruční opravě (viz rebuild-stav.php).
 */
function mc_recompute_stav(): array {
    $config = mc_load_config();
    $log = mc_read_log();

    // "vybráno" se počítá čistě součtem z logu — zaklad v mc.json je jen
    // kontrolní hodnota (počáteční dary musí v logu dát dohromady stejně
    // jako zaklad), ne samostatná položka k přičtení. Jinak by se zárodečné
    // dary započítaly dvakrát.
    $mc = [];
    foreach ($config as $key => $d) {
        $mc[$key] = [
            'vybrano'  => 0,
            'cil'      => (int)($d['cil'] ?? 0),
            'schranky' => (int)($d['schranky'] ?? 0),
        ];
    }

    $darcu = 0;
    foreach ($log as $row) {
        $key = (string)$row['mc'];
        $castka = (int)$row['castka'];
        if (isset($mc[$key])) {
            $mc[$key]['vybrano'] += $castka;
        }
        $darcu++;
    }

    $vybranoCelkem = 0;
    $cilCelkem = 0;
    foreach ($mc as $d) {
        $vybranoCelkem += $d['vybrano'];
        $cilCelkem += $d['cil'];
    }

    $stav = [
        'aktualizovano' => date('c'),
        'celkem' => [
            'vybrano' => $vybranoCelkem,
            'cil'     => $cilCelkem,
            'darcu'   => $darcu,
        ],
        'mc' => $mc,
    ];

    $tmp = mc_stav_path() . '.tmp.' . bin2hex(random_bytes(4));
    file_put_contents($tmp, json_encode($stav, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, mc_stav_path());

    return $stav;
}

/**
 * Připíše dar do logu a přepočte stav.json — obojí pod jedním zámkem, ať
 * se při dvou současných darech nic neztratí ani nepřepíše napůl.
 */
function mc_append_donation(string $mcKey, int $amount, string $metoda): array {
    if (!is_dir(mc_data_dir())) {
        mkdir(mc_data_dir(), 0755, true);
    }
    $fp = fopen(mc_lock_path(), 'c');
    if ($fp === false) {
        throw new RuntimeException('Zámek se nepodařilo otevřít');
    }
    flock($fp, LOCK_EX);
    try {
        $record = [
            'cas'    => date('c'),
            'mc'     => $mcKey,
            'castka' => $amount,
            'metoda' => $metoda,
        ];
        file_put_contents(mc_log_path(), json_encode($record, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return mc_recompute_stav();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
