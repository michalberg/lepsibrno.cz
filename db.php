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
    return $pdo;
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
