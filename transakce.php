<?php
/**
 * Administrace — výpis všech záznamů dárců z lokální SQLite evidence.
 *
 * Přístup je chráněn heslem (config['admin_password']). Po přihlášení se
 * nastaví podepsaná cookie s dlouhou platností, takže se nemusíš hlásit znovu.
 * Cookie = HMAC-SHA256 fixního payloadu klíčovaného heslem → změna hesla
 * automaticky zneplatní všechny staré cookies.
 *
 * Stránka NESMÍ být veřejná: tabulka donors obsahuje osobní údaje (GDPR).
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$adminPassword = (string)($config['admin_password'] ?? '');
const COOKIE_NAME = 'lb_admin';
const COOKIE_TTL  = 90 * 24 * 60 * 60; // 90 dní

// Token, který skončí v cookie. Bez znalosti hesla ho nelze zfalšovat.
function admin_token(string $password): string {
    return hash_hmac('sha256', 'lb-admin-v1', $password);
}

function is_logged_in(string $password): bool {
    if ($password === '') return false; // heslo nenastaveno → vše zamčeno
    $cookie = (string)($_COOKIE[COOKIE_NAME] ?? '');
    return $cookie !== '' && hash_equals(admin_token($password), $cookie);
}

function set_login_cookie(string $password): void {
    setcookie(COOKIE_NAME, admin_token($password), [
        'expires'  => time() + COOKIE_TTL,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_login_cookie(): void {
    setcookie(COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ── Odhlášení ────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    clear_login_cookie();
    header('Location: transakce.php');
    exit;
}

// ── Přihlášení (POST hesla) ──────────────────────────────────────────────
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($adminPassword !== '' && hash_equals($adminPassword, (string)$_POST['password'])) {
        set_login_cookie($adminPassword);
        header('Location: transakce.php');
        exit;
    }
    $loginError = 'Nesprávné heslo.';
}

// ── Brána ────────────────────────────────────────────────────────────────
if (!is_logged_in($adminPassword)) {
    http_response_code(401);
    render_login($loginError, $adminPassword === '');
    exit;
}

// ── Od sem dál je uživatel přihlášený ──────────────────────────────────────

// ── Smazání záznamu ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    donor_db()->prepare('DELETE FROM donors WHERE id = ?')->execute([$id]);
    update_matching_status();
    header('Location: transakce.php');
    exit;
}

$pdo  = donor_db();
$rows = $pdo->query('SELECT * FROM donors ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
// Jednorázové dary stažené z dary.zeleni.cz (mimo tabulku donors).
$onetime = $pdo->query('SELECT * FROM onetime_synced ORDER BY dary_created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
// Pravidelné dary založené přímo na dary.zeleni.cz (mimo lepsibrno.cz).
// Patří k „předplatnému" → níže je slučujeme s tabulkou donors do součtů i výpisu.
$recurring = $pdo->query('SELECT * FROM recurring_synced ORDER BY dary_created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

// Sloučené „předplatné": řádky z donors (web) + pravidelné dary z dary.zeleni.cz,
// normalizované do společného tvaru pro součty i výpis.
$subs = [];
foreach ($rows as $r) {
    // Za kampaň počítáme jednotně pravidlem 15. dne (viz installments_until),
    // ať Předplatné i tabulka Největší dárci ukazují u téhož dárce stejná čísla.
    $amount = $r['amount'] !== null ? (int)$r['amount'] : null;
    $months = installments_until((string)($r['created_at'] ?? ''), (string)$config['campaign_end']);
    $subs[] = [
        'id'       => (int)$r['id'],
        'date'     => (string)($r['created_at'] ?? ''),
        'method'   => (string)($r['payment_method'] ?? ''),
        'name'     => trim(($r['donor_name'] ?? '') . ' ' . ($r['donor_surname'] ?? '')),
        'email'    => (string)($r['donor_email'] ?? ''),
        'phone'    => (string)($r['donor_phone'] ?? ''),
        'amount'   => $amount,
        'months'   => $amount !== null ? $months : $r['months_left'],
        'campaign' => $amount !== null ? $amount * $months : null,
        'source'   => $r['utm_source'] ?: '(přímý / neznámý)',
        'content'  => (string)($r['utm_content'] ?? ''),
        'city'     => (string)($r['donor_city'] ?? ''),
        'origin'   => 'web',
    ];
}
foreach ($recurring as $r) {
    // Měsíce do voleb počítáme jednotně pravidlem 15. dne (viz installments_until),
    // ať Předplatné i tabulka Největší dárci ukazují stejné částky.
    $amount    = $r['amount'] !== null ? (int)$r['amount'] : null;
    $recMonths = installments_until((string)($r['dary_created_at'] ?? ''), (string)$config['campaign_end']);
    $subs[] = [
        'date'     => (string)($r['dary_created_at'] ?? ''),
        'method'   => 'transfer',
        'name'     => trim(($r['donor_name'] ?? '') . ' ' . ($r['donor_surname'] ?? '')),
        'email'    => (string)($r['donor_email'] ?? ''),
        'phone'    => (string)($r['donor_phone'] ?? ''),
        'amount'   => $amount,
        'months'   => $recMonths,
        'campaign' => $amount !== null ? $amount * $recMonths : null,
        'source'   => 'dary.zeleni.cz',
        'content'  => '',
        'city'     => (string)($r['donor_city'] ?? ''),
        'origin'   => 'dary',
    ];
}
usort($subs, fn($a, $b) => strcmp((string)$b['date'], (string)$a['date']));

// ── CSV export ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $which = $_GET['export'];
    $data  = ['onetime' => $onetime, 'recurring' => $recurring][$which] ?? $rows;
    $name  = ['onetime' => 'jednorazove', 'recurring' => 'pravidelne'][$which] ?? 'transakce';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, ať Excel pozná UTF-8
    if ($data) {
        fputcsv($out, array_keys($data[0]), ',', '"', '\\');
        foreach ($data as $r) fputcsv($out, $r, ',', '"', '\\');
    }
    fclose($out);
    exit;
}

// Souhrn jednorázových darů
$onetimeCount = count($onetime);
$onetimeSum   = array_sum(array_map(fn($r) => (int)($r['amount'] ?? 0), $onetime));

// ── Souhrny předplatného (donors + pravidelné z dary.zeleni.cz) ────────────
$count          = count($subs);
$recurringCount = count($recurring);
$sumMonthly     = 0;   // součet měsíčních částek
$sumCampaign    = 0;   // součet celkových darů za kampaň
$bySource       = [];  // zdroj => ['count' => n, 'monthly' => Kč, 'campaign' => Kč]
$byMonth        = [];  // YYYY-MM zadání => ['count' => n, 'monthly' => Kč]
$cardCampaign   = 0;   // součet za kampaň jen u plateb kartou (pro výpočet poplatku)

foreach ($subs as $s) {
    $amount   = (int)($s['amount'] ?? 0);
    $campaign = (int)($s['campaign'] ?? 0);
    $sumMonthly  += $amount;
    $sumCampaign += $campaign;
    if ($s['method'] === 'card') { $cardCampaign += $campaign; }

    $src = $s['source'];
    $bySource[$src]['count']    = ($bySource[$src]['count']    ?? 0) + 1;
    $bySource[$src]['monthly']  = ($bySource[$src]['monthly']  ?? 0) + $amount;
    $bySource[$src]['campaign'] = ($bySource[$src]['campaign'] ?? 0) + $campaign;

    $ym = substr((string)$s['date'], 0, 7) ?: '—'; // měsíc zadání předplatného
    $byMonth[$ym]['count']   = ($byMonth[$ym]['count']   ?? 0) + 1;
    $byMonth[$ym]['monthly'] = ($byMonth[$ym]['monthly'] ?? 0) + $amount;
}
uasort($bySource, fn($a, $b) => $b['count'] <=> $a['count']); // nejsilnější zdroje navrch
krsort($byMonth); // nejnovější měsíc navrch

// Poplatek za platby kartou (~3,1 %) — o tuto částku je čistý příjem z karet nižší.
$cardFeeRate = 0.031;
$cardFee     = (int)round($cardCampaign * $cardFeeRate);

// Počet měsíčních plateb od zadání daru do daného data. Měsíc zadání se počítá,
// jen pokud byl dar zadán do 15. dne (jinak první platba spadá až do dalšího měsíce).
function installments_until(string $createdAt, string $untilYmd): int {
    try {
        $from  = new DateTimeImmutable(substr($createdAt, 0, 10));
        $until = new DateTimeImmutable(substr($untilYmd, 0, 10));
    } catch (Throwable $e) {
        return 0;
    }
    $cursor = (int)$from->format('j') <= 15
        ? $from->modify('first day of this month')
        : $from->modify('first day of next month');
    $count = 0;
    while ($cursor <= $until) {
        $count++;
        $cursor = $cursor->modify('first day of next month');
    }
    return $count;
}

// Počet už proběhlých plateb k danému dni — podle SKUTEČNÉHO data (výročí dne
// zadání). Používá se pro „Již došlo" (realizované platby), kde nehraje roli
// pravidlo 15. dne — to platí jen pro očekávané platby do konce kampaně.
function installments_by_date(string $createdAt, string $untilYmd): int {
    try {
        $from  = new DateTimeImmutable(substr($createdAt, 0, 10));
        $until = new DateTimeImmutable(substr($untilYmd, 0, 10));
    } catch (Throwable $e) {
        return 0;
    }
    if ($from > $until) return 0;
    $count  = 0;
    $cursor = $from;
    while ($cursor <= $until) {
        $count++;
        $cursor = $cursor->modify('+1 month');
    }
    return $count;
}

// ── „Již došlo" — realizováno k dnešnímu dni ────────────────────────────────
// Jednorázové dary celé; pravidelné a předplatné měsíční částkou × počet plateb,
// které už reálně proběhly (podle data zadání, ne pravidla 15.).
$today     = date('Y-m-d');
$alreadyIn = $onetimeSum;
foreach ($rows as $r) {
    $alreadyIn += (int)($r['amount'] ?? 0) * installments_by_date((string)$r['created_at'], $today);
}
foreach ($recurring as $r) {
    $alreadyIn += (int)($r['amount'] ?? 0) * installments_by_date((string)$r['dary_created_at'], $today);
}

// ── Největší dárci ──────────────────────────────────────────────────────────
// Sloučeno dle jména+příjmení napříč jednorázovými i pravidelnými dary.
// Pravidelné se počítají měsíční částkou × počet plateb od zadání do voleb.
$campaignEnd = (string)$config['campaign_end'];
$topDonors   = []; // key => ['name' => ..., 'onetime' => Kč, 'recurring' => Kč]

function donor_key(string $name, string $surname): string {
    return mb_strtolower(trim($name)) . '|' . mb_strtolower(trim($surname));
}
function topdonor_add(array &$top, string $name, string $surname, int $onetime, int $recurring): void {
    $key = donor_key($name, $surname);
    if ($key === '|') return; // bez jména přeskoč
    if (!isset($top[$key])) {
        $top[$key] = ['name' => trim($name . ' ' . $surname), 'onetime' => 0, 'recurring' => 0];
    }
    $top[$key]['onetime']   += $onetime;
    $top[$key]['recurring'] += $recurring;
}

foreach ($onetime as $r) {
    topdonor_add($topDonors, (string)$r['donor_name'], (string)$r['donor_surname'], (int)($r['amount'] ?? 0), 0);
}
foreach ($rows as $r) { // předplatné z lepsibrno.cz (donors)
    $rec = (int)($r['amount'] ?? 0) * installments_until((string)$r['created_at'], $campaignEnd);
    topdonor_add($topDonors, (string)$r['donor_name'], (string)$r['donor_surname'], 0, $rec);
}
foreach ($recurring as $r) { // pravidelné přímo z dary.zeleni.cz
    $rec = (int)($r['amount'] ?? 0) * installments_until((string)$r['dary_created_at'], $campaignEnd);
    topdonor_add($topDonors, (string)$r['donor_name'], (string)$r['donor_surname'], 0, $rec);
}
foreach ($topDonors as &$d) { $d['total'] = $d['onetime'] + $d['recurring']; }
unset($d);
uasort($topDonors, fn($a, $b) => $b['total'] <=> $a['total']);

function status_label(string $s): string {
    return [
        'paid'   => 'Zaplaceno (karta)',
        'intent' => 'Záměr (převod)',
    ][$s] ?? $s;
}

function kc(int $n): string {
    return number_format($n, 0, ',', ' ') . ' Kč';
}

// ── Login formulář (helper, volá se před exit) ──────────────────────────────
function render_login(string $error, bool $notConfigured): void {
    ?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Přihlášení — administrace</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f6f4;margin:0;
       display:flex;min-height:100vh;align-items:center;justify-content:center;color:#1a2e1a}
  .card{background:#fff;padding:2rem;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);width:min(360px,90vw)}
  h1{font-size:1.25rem;margin:0 0 1rem}
  input{width:100%;box-sizing:border-box;padding:.7rem;font-size:1rem;border:1px solid #cbd5cb;border-radius:8px}
  button{margin-top:1rem;width:100%;padding:.7rem;font-size:1rem;font-weight:600;color:#fff;
         background:#2e7d32;border:0;border-radius:8px;cursor:pointer}
  button:hover{background:#256628}
  .err{color:#c62828;font-size:.9rem;margin:.6rem 0 0}
  .warn{color:#9a6700;background:#fff8e1;padding:.7rem;border-radius:8px;font-size:.85rem}
</style></head><body>
  <form class="card" method="post" action="transakce.php">
    <h1>Administrace dárců</h1>
    <?php if ($notConfigured): ?>
      <p class="warn">Heslo administrace není nastavené (chybí <code>ADMIN_PASSWORD</code>). Bez něj je přístup zamčený.</p>
    <?php else: ?>
      <input type="password" name="password" placeholder="Heslo" autofocus autocomplete="current-password">
      <button type="submit">Přihlásit</button>
    <?php endif; ?>
    <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  </form>
</body></html><?php
}
?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Transakce — administrace</title>
<style>
  :root{--green:#2e7d32;--ink:#1a2e1a;--muted:#5a6b5a;--line:#e2e8e2}
  *{box-sizing:border-box}
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f6f4;color:var(--ink);margin:0;padding:1.5rem}
  .wrap{max-width:1400px;margin:0 auto}
  header{display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;margin-bottom:1.5rem}
  h1{font-size:1.4rem;margin:0}
  h2{font-size:1.05rem;margin:2rem 0 .75rem}
  .actions a{display:inline-block;text-decoration:none;font-weight:600;padding:.5rem .9rem;border-radius:8px;font-size:.9rem}
  .actions a.primary{background:var(--green);color:#fff}
  .actions a.ghost{color:var(--muted);border:1px solid var(--line)}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}
  .card{background:#fff;padding:1rem 1.1rem;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.05)}
  .card .label{font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.03em}
  .card .value{font-size:1.5rem;font-weight:700;margin-top:.25rem}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;
        box-shadow:0 1px 4px rgba(0,0,0,.05);font-size:.85rem}
  th,td{text-align:left;padding:.55rem .7rem;border-bottom:1px solid var(--line);white-space:nowrap}
  th{background:#eef3ee;font-weight:600;color:var(--muted);font-size:.75rem;text-transform:uppercase;letter-spacing:.02em}
  tbody tr:hover{background:#f8faf8}
  .scroll{overflow-x:auto;border-radius:10px}
  .tag{display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.75rem;font-weight:600}
  .tag.paid{background:#e6f4ea;color:#1b5e20}
  .tag.intent{background:#fff3e0;color:#e65100}
  .tag.other{background:#eceff1;color:#455a64}
  .num{text-align:right;font-variant-numeric:tabular-nums}
  .muted{color:var(--muted)}
  .empty{background:#fff;padding:2rem;text-align:center;border-radius:10px;color:var(--muted)}
</style></head><body>
<div class="wrap">
  <header>
    <h1>Informace o darech</h1>
    <div class="actions">
      <a class="ghost" href="telefundraising.php">📞 Telefundraising</a>
      <a class="primary" href="transakce.php?export=csv">⬇ Předplatné CSV</a>
      <?php if ($recurringCount): ?><a class="primary" href="transakce.php?export=recurring">⬇ Pravidelné (dary.zeleni.cz) CSV</a><?php endif; ?>
      <?php if ($onetimeCount): ?><a class="primary" href="transakce.php?export=onetime">⬇ Jednorázové CSV</a><?php endif; ?>
      <a class="ghost" href="transakce.php?logout=1">Odhlásit</a>
    </div>
  </header>

  <div class="cards">
    <div class="card">
      <div class="label">Předplatných</div>
      <div class="value"><?= $count ?></div>
      <?php if ($recurringCount): ?><div class="muted" style="font-size:.8rem;margin-top:.25rem"><?= $recurringCount ?> mimo lepsibrno.cz</div><?php endif; ?>
    </div>
    <div class="card"><div class="label">Měsíčně celkem</div><div class="value"><?= kc($sumMonthly) ?></div></div>
    <div class="card"><div class="label">Za kampaň celkem</div><div class="value"><?= kc($sumCampaign) ?></div></div>
    <?php if ($onetimeCount): ?>
      <div class="card"><div class="label">Jednorázových darů</div><div class="value"><?= $onetimeCount ?></div></div>
      <div class="card"><div class="label">Jednorázové celkem</div><div class="value"><?= kc($onetimeSum) ?></div></div>
    <?php endif; ?>
    <div class="card">
      <div class="label">Za kampaň očekávané</div>
      <div class="value"><?= kc($sumCampaign + $onetimeSum) ?></div>
      <div class="muted" style="font-size:.8rem;margin-top:.25rem">Již došlo: <?= kc($alreadyIn) ?></div>
      <div class="muted" style="font-size:.8rem;margin-top:.15rem">Poplatek za kartu (3,1 %): −<?= kc($cardFee) ?></div>
    </div>
  </div>

  <h2>Předplatné <span class="muted">(<?= $count ?>)</span></h2>

  <h3 style="font-size:.95rem;margin:1rem 0 .6rem;color:var(--muted)">Podle zdroje (UTM source)</h3>
  <div class="scroll">
    <table>
      <thead><tr><th>Zdroj</th><th class="num">Počet</th><th class="num">Měsíčně</th><th class="num">Za kampaň</th></tr></thead>
      <tbody>
      <?php foreach ($bySource as $src => $d): ?>
        <tr>
          <td><?= h((string)$src) ?></td>
          <td class="num"><?= $d['count'] ?></td>
          <td class="num"><?= kc($d['monthly']) ?></td>
          <td class="num"><?= kc($d['campaign']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <p class="muted" style="font-size:.85rem;margin:1.5rem 0 .4rem">
    Zadáno podle měsíce (měsíční výše předplatných) — kvůli matchingu:
    <?php foreach ($byMonth as $ym => $d): ?>
      <span style="display:inline-block;margin-right:1rem"><strong><?= h($ym) ?></strong>: <?= $d['count'] ?>× / <?= kc($d['monthly']) ?></span>
    <?php endforeach; ?>
  </p>

  <h3 style="font-size:.95rem;margin:.4rem 0 .6rem;color:var(--muted)">Všechna předplatné <span class="muted">(včetně pravidelných z dary.zeleni.cz)</span></h3>
  <?php if (!$subs): ?>
    <div class="empty">Zatím žádné záznamy.</div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th>Datum</th><th>Metoda</th><th>Jméno</th><th>E-mail</th><th>Telefon</th>
        <th class="num">Měsíčně</th><th class="num">Měsíců</th><th class="num">Za kampaň</th>
        <th>Source</th><th>Content</th><th>Město</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($subs as $s):
        $m = $s['method'];
        $micon  = $m === 'card' ? '💳' : ($m === 'transfer' ? '🏦' : '•');
        $mlabel = $m === 'card' ? 'Karta' : ($m === 'transfer' ? 'Převod' : $m);
        if ($s['origin'] === 'dary') { $mlabel .= ' · dary.zeleni.cz'; }
      ?>
        <tr>
          <td class="muted"><?= h($s['date']) ?></td>
          <td style="text-align:center" title="<?= h($mlabel) ?>"><?= $micon ?></td>
          <td><?= h($s['name']) ?></td>
          <td><?= h($s['email']) ?></td>
          <td><?= h($s['phone']) ?></td>
          <td class="num"><?= $s['amount'] !== null ? kc((int)$s['amount']) : '—' ?></td>
          <td class="num"><?= h((string)$s['months']) ?></td>
          <td class="num"><?= $s['campaign'] !== null ? kc((int)$s['campaign']) : '—' ?></td>
          <td><?= h((string)$s['source']) ?></td>
          <td><?= h($s['content']) ?></td>
          <td><?= h($s['city']) ?></td>
          <td><?php if ($s['origin'] === 'web'): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('Smazat záznam <?= h($s['name']) ?> (<?= h($s['email']) ?>)?')">
              <input type="hidden" name="delete_id" value="<?= (int)$s['id'] ?>">
              <button type="submit" style="background:none;border:1px solid #e0a0a0;color:#c62828;border-radius:5px;padding:.2rem .5rem;cursor:pointer;font-size:.8rem" title="Smazat záznam">×</button>
            </form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <h2>Jednorázové dary <span class="muted">(z dary.zeleni.cz)</span></h2>
  <?php if (!$onetime): ?>
    <div class="empty">Zatím žádné jednorázové dary. Naplní je <code>sync-onetime.php</code> při běhu cronu.</div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th>Datum</th><th>Jméno</th><th>E-mail</th><th>Telefon</th>
        <th class="num">Částka</th><th>VS</th><th>Město</th><th>Synced</th>
      </tr></thead>
      <tbody>
      <?php foreach ($onetime as $r): ?>
        <tr>
          <td class="muted"><?= h(substr((string)$r['dary_created_at'], 0, 10)) ?></td>
          <td><?= h(trim(($r['donor_name'] ?? '') . ' ' . ($r['donor_surname'] ?? ''))) ?></td>
          <td><?= h($r['donor_email']) ?></td>
          <td><?= h($r['donor_phone']) ?></td>
          <td class="num"><?= $r['amount'] !== null ? kc((int)$r['amount']) : '—' ?></td>
          <td><?= h($r['vs']) ?></td>
          <td><?= h($r['donor_city']) ?></td>
          <td class="muted"><?= h(substr((string)$r['synced_at'], 0, 10)) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <h2>Největší dárci <span class="muted">(jednorázové + pravidelné do voleb, dle jména)</span></h2>
  <?php if (!$topDonors): ?>
    <div class="empty">Zatím žádní dárci.</div>
  <?php else: ?>
  <div class="scroll">
    <table>
      <thead><tr>
        <th>Dárce</th><th class="num">Jednorázově</th><th class="num">Pravidelně do voleb</th><th class="num">Celkem</th>
      </tr></thead>
      <tbody>
      <?php foreach ($topDonors as $d): ?>
        <tr>
          <td><?= h($d['name']) ?></td>
          <td class="num"><?= $d['onetime'] ? kc($d['onetime']) : '—' ?></td>
          <td class="num"><?= $d['recurring'] ? kc($d['recurring']) : '—' ?></td>
          <td class="num"><strong><?= kc($d['total']) ?></strong></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
</body></html>
