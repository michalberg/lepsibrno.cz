<?php
/**
 * Lokální evidence dárců v SQLite. Soubor leží MIMO webroot (parent dir),
 * takže není přístupný přes HTTP. Otevírá se na požádání, schéma se vytvoří
 * při prvním zápisu.
 *
 * Použití:
 *   require __DIR__ . '/db.php';
 *   record_donor([...]);
 */

declare(strict_types=1);

function donor_db_path(): string {
    return __DIR__ . '/../donors.db';
}

function donor_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $pdo = new PDO('sqlite:' . donor_db_path());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS donors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            payment_method TEXT NOT NULL,
            status TEXT NOT NULL,
            donor_name TEXT,
            donor_surname TEXT,
            donor_birth TEXT,
            donor_email TEXT,
            donor_phone TEXT,
            donor_address TEXT,
            donor_city TEXT,
            donor_zip TEXT,
            amount INTEGER,
            months_left INTEGER,
            total_campaign INTEGER,
            utm_source TEXT,
            utm_medium TEXT,
            utm_campaign TEXT,
            utm_content TEXT,
            utm_term TEXT,
            referrer TEXT,
            landing_page TEXT,
            stripe_session_id TEXT,
            stripe_subscription_id TEXT,
            variable_symbol TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_donors_email ON donors(donor_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_donors_created ON donors(created_at)");
    // Evidence jednorázových darů z dary.zeleni.cz už odeslaných do Action Networku.
    // payment_id = _id platby z dary API → zabrání opakovanému odeslání.
    // Drží i údaje dárce, aby šly zobrazit v transakce.php (mimo tabulku donors,
    // ať neovlivní matching ukazatel, který sčítá donors.amount).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS onetime_synced (
            payment_id      TEXT PRIMARY KEY,
            synced_at       TEXT NOT NULL DEFAULT (datetime('now')),
            dary_created_at TEXT,
            donor_name      TEXT,
            donor_surname   TEXT,
            donor_email     TEXT,
            donor_phone     TEXT,
            donor_city      TEXT,
            amount          INTEGER,
            vs              TEXT,
            status          TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_onetime_created ON onetime_synced(dary_created_at)");
    // Evidence PRAVIDELNÝCH darů založených PŘÍMO na dary.zeleni.cz (mimo lepsibrno.cz).
    // Rozlišujeme je dle periodicity: dary z lepsibrno.cz mají 'monthly', přímé 'month'.
    // payment_id = _id platby z dary API → zabrání opakovanému odeslání do AN.
    // Drží i přepočet na kampaň (months_left, total_campaign), aby šly započítat
    // do součtů „předplatného" v transakce.php (stejně jako řádky tabulky donors).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recurring_synced (
            payment_id      TEXT PRIMARY KEY,
            synced_at       TEXT NOT NULL DEFAULT (datetime('now')),
            dary_created_at TEXT,
            donor_name      TEXT,
            donor_surname   TEXT,
            donor_email     TEXT,
            donor_phone     TEXT,
            donor_city      TEXT,
            amount          INTEGER,
            months_left     INTEGER,
            total_campaign  INTEGER,
            vs              TEXT,
            status          TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_recurring_created ON recurring_synced(dary_created_at)");
    // Jednorázové dary na čtvrť (ctvrte.html — inzerce v radničních
    // zpravodajích). Souhrn bez osobních údajů žije v /data/dary.jsonl +
    // /stav.json (mc-store.php) — tohle je jen admin evidence PRO
    // transakce.php, ať jde dohledat, kdo a kdy skutečně přispěl. Zapisuje
    // se v log-district-donation.php (převod) a stripe-webhook.php (karta),
    // vždy spolu s mc_append_donation() do dary.jsonl.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS district_donations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            mc TEXT NOT NULL,
            mc_nazev TEXT,
            payment_method TEXT NOT NULL,
            amount INTEGER,
            donor_name TEXT,
            donor_surname TEXT,
            donor_email TEXT,
            donor_phone TEXT,
            donor_city TEXT,
            variable_symbol TEXT,
            stripe_session_id TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_district_donations_mc ON district_donations(mc)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_district_donations_created ON district_donations(created_at)");
    // Shoda se seznamem telefundraisingu (viz refresh_telefundraising_matches) —
    // sloupec musí existovat hned, i než poprvé proběhne přepočet.
    foreach (['donors', 'onetime_synced', 'recurring_synced'] as $table) {
        ensure_column($pdo, $table, 'tf_match', 'INTEGER NOT NULL DEFAULT 0');
    }
    return $pdo;
}

/**
 * Zapíše dar na čtvrť do admin evidence (district_donations). Vrátí ID
 * nového řádku nebo null při chybě. Volá se VEDLE mc_append_donation()
 * (mc-store.php) — ta píše do dary.jsonl/stav.json bez osobních údajů,
 * tahle tabulka je jen pro dohledání konkrétního dárce v transakce.php.
 */
function record_district_donation(array $data): ?int {
    $cols = [
        'mc', 'mc_nazev', 'payment_method', 'amount',
        'donor_name', 'donor_surname', 'donor_email', 'donor_phone', 'donor_city',
        'variable_symbol', 'stripe_session_id',
    ];
    try {
        $pdo = donor_db();
        $placeholders = implode(',', array_map(fn($c) => ':' . $c, $cols));
        $sql = 'INSERT INTO district_donations (' . implode(',', $cols) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($cols as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt->execute($params);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('record_district_donation error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Zapíše záznam dárce. Vrátí ID nového řádku nebo null při chybě.
 * Pole, která chybí v $data, se uloží jako NULL.
 */
function record_donor(array $data): ?int {
    $cols = [
        'payment_method', 'status',
        'donor_name', 'donor_surname', 'donor_birth', 'donor_email', 'donor_phone',
        'donor_address', 'donor_city', 'donor_zip',
        'amount', 'months_left', 'total_campaign',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'referrer', 'landing_page',
        'stripe_session_id', 'stripe_subscription_id', 'variable_symbol',
    ];
    try {
        $pdo = donor_db();
        $placeholders = implode(',', array_map(fn($c) => ':' . $c, $cols));
        $sql = 'INSERT INTO donors (' . implode(',', $cols) . ") VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $params = [];
        foreach ($cols as $c) {
            $params[':' . $c] = $data[$c] ?? null;
        }
        $stmt->execute($params);
        $id = (int)$pdo->lastInsertId();
        update_matching_status();
        return $id;
    } catch (Throwable $e) {
        error_log('record_donor error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Přepočte celkovou částku z prvních darů a zapíše do matching-status.json
 * v webrootu (tj. v adresáři tohoto skriptu). Frontend ho fetchne a vykreslí.
 */
function update_matching_status(): void {
    // Startovní hodnota — započítává dary mimo systém (např. dárci, kteří
    // dorazili předtím, než byl spuštěn formulář).
    $baseline = 6200;
    $cap = 50000;
    try {
        $pdo = donor_db();
        $total = (int)$pdo->query('SELECT COALESCE(SUM(amount), 0) FROM donors')->fetchColumn();
        $payload = json_encode([
            'matched' => min($total + $baseline, $cap),
            'cap'     => $cap,
            'updated' => date('c'),
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents(__DIR__ . '/matching-status.json', $payload, LOCK_EX);
    } catch (Throwable $e) {
        error_log('update_matching_status error: ' . $e->getMessage());
    }
}

/** Normalizace jména pro porovnání napříč tabulkami (lowercase, sjednocené mezery). */
function normalize_donor_name(string $s): string {
    $s = preg_replace('/\s+/', ' ', trim($s)) ?? '';
    return mb_strtolower($s, 'UTF-8');
}

/**
 * Set normalizovaných jmen (jméno+příjmení) z telefundraising.db — kontakty,
 * které byly/budou telefonicky osloveny. Soubor leží mimo webroot vedle
 * donors.db; pokud neexistuje (telefundraising.php ještě nebyl spuštěn),
 * vrací prázdné pole.
 */
function telefundraising_name_set(): array {
    $path = __DIR__ . '/../telefundraising.db';
    if (!is_readable($path)) return [];
    try {
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $db->query('SELECT jmeno FROM tf_contacts')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
    $set = [];
    foreach ($rows as $jmeno) {
        $key = normalize_donor_name((string)$jmeno);
        if ($key !== '') $set[$key] = true;
    }
    return $set;
}

/** Přidá sloupec do tabulky, pokud tam ještě není (kvůli existujícím DB souborům). */
function ensure_column(PDO $pdo, string $table, string $column, string $type): void {
    $names = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array($column, $names, true)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
    }
}

/**
 * Přepočte shodu jména+příjmení se seznamem z telefundraising.db a uloží ji
 * do sloupce tf_match (donors / onetime_synced / recurring_synced). Volá se
 * ze sync-onetime.php po každém běhu cronu (jednou denně) — NE při každém
 * načtení transakce.php, ať admin stránka zůstává rychlá a nesahá zbytečně
 * na telefundraising.db.
 */
function refresh_telefundraising_matches(): void {
    $pdo   = donor_db(); // mj. zajistí sloupec tf_match, viz výše
    $names = telefundraising_name_set();
    foreach (['donors', 'onetime_synced', 'recurring_synced'] as $table) {
        // "AS rid": donors má INTEGER PRIMARY KEY (id), takže SQLite by "rowid"
        // ve výstupu přejmenovalo na "id" — alias sjednotí název napříč tabulkami.
        $rows = $pdo->query("SELECT rowid AS rid, donor_name, donor_surname FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        $upd  = $pdo->prepare("UPDATE $table SET tf_match = :m WHERE rowid = :id");
        foreach ($rows as $r) {
            $key   = normalize_donor_name(trim(($r['donor_name'] ?? '') . ' ' . ($r['donor_surname'] ?? '')));
            $match = isset($names[$key]) ? 1 : 0;
            $upd->execute([':m' => $match, ':id' => $r['rid']]);
        }
    }
}
