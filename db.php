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
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('record_donor error: ' . $e->getMessage());
        return null;
    }
}
