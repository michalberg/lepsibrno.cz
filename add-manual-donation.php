<?php
/**
 * Ruční zápis daru, který systém sám nezachytí — typicky dar zaslaný na
 * dary.zeleni.cz pod jiným fondem než "brno" (sync-onetime.php stahuje
 * jen fond config['dary_fund_id'], takže takový dar nikdy nenačte).
 *
 * Zapisuje do stejné tabulky jako sync-onetime.php (onetime_synced), aby
 * se dar objevil v transakce.php jako běžný jednorázový dar. payment_id
 * dostane prefix "manual-", ať se nikdy nesrazí se skutečným ID z API.
 *
 * Přístup je chráněný stejnou cookie jako transakce.php (viz tam) — pokud
 * jsi tam přihlášený, jsi přihlášený i sem.
 */

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$adminPassword = (string)($config['admin_password'] ?? '');
const COOKIE_NAME = 'lb_admin';

function admin_token(string $password): string {
    return hash_hmac('sha256', 'lb-admin-v1', $password);
}

function is_logged_in(string $password): bool {
    if ($password === '') return false;
    $cookie = (string)($_COOKIE[COOKIE_NAME] ?? '');
    return $cookie !== '' && hash_equals(admin_token($password), $cookie);
}

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (!is_logged_in($adminPassword)) {
    http_response_code(401);
    echo 'Nepřihlášeno. Přihlas se nejdřív na <a href="transakce.php">transakce.php</a>.';
    exit;
}

$error = '';
$done  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim((string)($_POST['name'] ?? ''));
    $surname = trim((string)($_POST['surname'] ?? ''));
    $amount  = (int)($_POST['amount'] ?? 0);

    if ($name === '' && $surname === '') {
        $error = 'Vyplň aspoň jméno nebo příjmení.';
    } elseif ($amount <= 0) {
        $error = 'Částka musí být kladné číslo.';
    } else {
        $pdo = donor_db();
        $stmt = $pdo->prepare(
            'INSERT INTO onetime_synced
                (payment_id, dary_created_at, donor_name, donor_surname, donor_email,
                 donor_phone, donor_city, donor_birth, donor_address, donor_zip,
                 amount, vs, status, kampan)
             VALUES (:pid, :created, :name, :surname, :email, :phone, :city,
                     :birth, :address, :zip, :amount, :vs, :status, :kampan)'
        );
        $stmt->execute([
            ':pid'     => 'manual-' . bin2hex(random_bytes(6)),
            ':created' => (string)($_POST['created'] ?? date('Y-m-d')),
            ':name'    => $name,
            ':surname' => $surname,
            ':email'   => trim((string)($_POST['email'] ?? '')),
            ':phone'   => trim((string)($_POST['phone'] ?? '')),
            ':city'    => trim((string)($_POST['city'] ?? '')),
            ':birth'   => trim((string)($_POST['birth'] ?? '')),
            ':address' => trim((string)($_POST['address'] ?? '')),
            ':zip'     => trim((string)($_POST['zip'] ?? '')),
            ':amount'  => $amount,
            ':vs'      => trim((string)($_POST['vs'] ?? '')),
            ':status'  => trim((string)($_POST['status'] ?? '')) ?: 'promised',
            ':kampan'  => trim((string)($_POST['kampan'] ?? '')),
        ]);
        $done = true;
    }
}
?><!doctype html>
<html lang="cs"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ruční zápis daru — administrace</title>
<style>
  body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f4f6f4;margin:0;
       padding:2rem 1rem;color:#1a2e1a}
  .card{background:#fff;padding:2rem;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);
        max-width:480px;margin:0 auto}
  h1{font-size:1.25rem;margin:0 0 1.2rem}
  label{display:block;font-size:.85rem;font-weight:600;margin:.8rem 0 .3rem}
  input,select{width:100%;box-sizing:border-box;padding:.6rem;font-size:1rem;
               border:1px solid #cbd5cb;border-radius:8px}
  button{margin-top:1.4rem;width:100%;padding:.7rem;font-size:1rem;font-weight:600;color:#fff;
         background:#2e7d32;border:0;border-radius:8px;cursor:pointer}
  button:hover{background:#256628}
  .err{color:#c62828;font-size:.9rem;margin:.6rem 0 0}
  .ok{color:#2e7d32;background:#eaf4ea;padding:.8rem;border-radius:8px;font-size:.9rem;margin-bottom:1rem}
  .back{display:inline-block;margin-top:1rem;font-size:.9rem;color:#2e7d32}
  .row2{display:flex;gap:.6rem}
  .row2>div{flex:1}
</style></head><body>
  <div class="card">
    <h1>Ruční zápis daru</h1>
    <p style="font-size:.85rem;color:#555;margin-top:-.6rem">
      Pro dary, které systém sám nezachytí — např. platba na dary.zeleni.cz
      pod jiným fondem než „brno". Zapíše se mezi jednorázové dary v
      <a href="transakce.php">transakce.php</a>.
    </p>
    <?php if ($done): ?>
      <p class="ok">Dar zapsán.</p>
      <a class="back" href="transakce.php">← Zpět na transakce.php</a>
    <?php else: ?>
    <form method="post">
      <div class="row2">
        <div><label>Jméno</label><input name="name" value="<?= h($_POST['name'] ?? '') ?>"></div>
        <div><label>Příjmení</label><input name="surname" value="<?= h($_POST['surname'] ?? '') ?>"></div>
      </div>
      <label>Datum narození</label>
      <input name="birth" placeholder="DD.MM.RRRR" value="<?= h($_POST['birth'] ?? '') ?>">
      <label>E-mail</label>
      <input type="email" name="email" value="<?= h($_POST['email'] ?? '') ?>">
      <label>Mobil</label>
      <input name="phone" value="<?= h($_POST['phone'] ?? '') ?>">
      <label>Ulice</label>
      <input name="address" value="<?= h($_POST['address'] ?? '') ?>">
      <div class="row2">
        <div><label>Město</label><input name="city" value="<?= h($_POST['city'] ?? '') ?>"></div>
        <div><label>PSČ</label><input name="zip" value="<?= h($_POST['zip'] ?? '') ?>"></div>
      </div>
      <div class="row2">
        <div><label>Částka (Kč)</label><input type="number" name="amount" value="<?= h($_POST['amount'] ?? '') ?>"></div>
        <div><label>VS</label><input name="vs" value="<?= h($_POST['vs'] ?? '') ?>"></div>
      </div>
      <div class="row2">
        <div>
          <label>Kampaň</label>
          <input name="kampan" value="<?= h($_POST['kampan'] ?? '') ?>" placeholder="např. hlavni">
        </div>
        <div>
          <label>Datum daru</label>
          <input type="date" name="created" value="<?= h($_POST['created'] ?? date('Y-m-d')) ?>">
        </div>
      </div>
      <label>Stav</label>
      <select name="status">
        <option value="promised" <?= ($_POST['status'] ?? '') === 'promised' ? 'selected' : '' ?>>promised (příslib)</option>
        <option value="paid" <?= ($_POST['status'] ?? '') === 'paid' ? 'selected' : '' ?>>paid</option>
      </select>
      <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
      <button type="submit">Zapsat dar</button>
    </form>
    <?php endif; ?>
  </div>
</body></html>
