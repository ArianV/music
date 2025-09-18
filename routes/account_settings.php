<?php
// routes/account_settings.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$u = current_user();
$errors = [];
$saved  = false;

// helpers (same rules as before)
function normalize_handle(string $h): string {
  $h = strtolower(trim($h));
  $h = preg_replace('/[^a-z0-9_]/', '', $h);
  return substr($h, 0, 20);
}
function handle_is_valid(string $h): bool {
  return (bool)preg_match('/^[a-z0-9_]{3,20}$/', $h);
}
function clean_phone(?string $p): ?string {
  if ($p === null) return null;
  $p = trim($p);
  if ($p === '') return null;
  $p = ltrim($p, '+');
  $p = preg_replace('/\D+/', '', $p);
  return $p ? ('+' . $p) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $which = $_POST['form'] ?? '';

  if ($which === 'basics') {
    $email  = trim($_POST['email'] ?? '');
    $handle = normalize_handle($_POST['handle'] ?? ($u['handle'] ?? ''));
    $phone  = clean_phone($_POST['phone'] ?? null);

    // validate email format + uniqueness (except self)
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'Please enter a valid email address.';
    } else {
      $st = db()->prepare('SELECT 1 FROM users WHERE lower(email)=lower(:e) AND id<>:me LIMIT 1');
      $st->execute([':e'=>$email, ':me'=>$u['id']]);
      if ($st->fetch()) $errors[] = 'That email is already in use.';
    }

    // handle validate + uniqueness (except self)
    if (!handle_is_valid($handle)) {
      $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores).';
    } else {
      $st = db()->prepare('SELECT 1 FROM users WHERE lower(handle)=lower(:h) AND id<>:me LIMIT 1');
      $st->execute([':h'=>$handle, ':me'=>$u['id']]);
      if ($st->fetch()) $errors[] = 'That username is taken.';
    }

    if (!$errors) {
      // Update handle/phone immediately
      $sets   = ['handle=:h'];
      $params = [':h'=>$handle, ':id'=>$u['id']];

      if (function_exists('table_has_column') && table_has_column(db(),'users','phone')) {
        $sets[] = 'phone=:p'; $params[':p'] = $phone;
      }
      if (function_exists('table_has_column') && table_has_column(db(),'users','updated_at')) {
        $sets[] = 'updated_at=NOW()';
      }
      $sql = 'UPDATE users SET '.implode(', ',$sets).' WHERE id=:id';
      db()->prepare($sql)->execute($params);

      // If email changed, start verification to NEW email (do NOT switch yet)
      if (strcasecmp($email, (string)($u['email'] ?? '')) !== 0) {
        begin_email_change((int)$u['id'], $email);
        $saved = true;
        $u = current_user();
      } else {
        $saved = true;
        $u = current_user();
      }
    }
  }

  if ($which === 'password') {
    $cur = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $rep = (string)($_POST['repeat_password'] ?? '');

    if ($new === '' || $rep === '') {
      $errors[] = 'Please enter your new password twice.';
    } elseif ($new !== $rep) {
      $errors[] = 'New passwords do not match.';
    } elseif (strlen($new) < 8) {
      $errors[] = 'New password must be at least 8 characters.';
    } elseif (!password_verify($cur, (string)($u['password_hash'] ?? ''))) {
      $errors[] = 'Current password is incorrect.';
    }

    if (!$errors) {
      $hash = password_hash($new, PASSWORD_DEFAULT);
      $st = db()->prepare('UPDATE users SET password_hash=:h'.(function_exists('table_has_column') && table_has_column(db(),'users','updated_at')? ', updated_at=NOW()' : '').' WHERE id=:id');
      $st->execute([':h'=>$hash, ':id'=>$u['id']]);
      $saved = true;
    }
  }
}

$title = 'Account Settings';
$head = <<<CSS
<style>
.sets-wrap{max-width:860px;margin:28px auto;padding:0 16px}
.card{background:#111318;border:1px solid #1f2430;border-radius:14px;padding:18px;margin-bottom:16px}
h1{margin:0 0 14px}
.row{margin-bottom:12px}
label{display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px}
input[type="text"],input[type="email"],input[type="password"],select{
  width:100%;box-sizing:border-box;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px
}
.btn{display:inline-flex;align-items:center;gap:8px;background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}
.btn.secondary{background:#1f2937;color:#e5e7eb;border:1px solid #2a3344}
.muted{color:#9ca3af;font-size:12px}
.notice{margin-bottom:12px;border-radius:8px;padding:10px 12px}
.notice.ok{background:#0b2f22;color:#bbf7d0;border:1px solid #22c55e}
.notice.err{background:#3a0d0d;color:#fecaca;border:1px solid #7f1d1d}
.inline-pill{display:inline-flex;align-items:center;padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid #2a3344;background:#0f1217}
</style>
CSS;

ob_start(); ?>
<div class="sets-wrap">
  <h1>Account settings</h1>

  <?php if ($saved && !$errors): ?>
    <div class="notice ok">Saved changes.</div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="notice err"><?= e(implode("\n", $errors)) ?></div>
  <?php endif; ?>

  <?php if (!empty($u['pending_email'])): ?>
    <div class="notice" style="background:#3a2a06;color:#fde68a;border:1px solid #a16207">
      Email change pending: <b><?= e($u['pending_email']) ?></b>. Check that inbox to confirm.
      <form method="post" action="<?= e(asset('verify-email-change/resend')) ?>" style="display:inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn secondary" style="height:28px;padding:0 10px;font-size:12px">Resend link</button>
      </form>
    </div>
  <?php endif; ?>

  <form method="post" class="card" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form" value="basics">

    <div class="row">
      <label>Username</label>
      <div style="display:flex;gap:8px;align-items:center">
        <span class="inline-pill">@</span>
        <input type="text" name="handle" value="<?= e($u['handle'] ?? '') ?>" placeholder="yourname" style="max-width:240px">
      </div>
      <div class="muted" style="margin-top:6px">3–20 chars, letters/numbers/underscore only. Changes your public URL.</div>
    </div>

    <div class="row">
      <label>Email</label>
      <input type="email" name="email" value="<?= e($u['email'] ?? '') ?>" placeholder="you@example.com">
      <div class="muted" style="margin-top:6px">We’ll send a confirmation to the new email. Your current email stays active until you confirm.</div>
    </div>

    <?php if (function_exists('table_has_column') && table_has_column(db(),'users','phone')): ?>
    <div class="row">
      <label>Phone (optional)</label>
      <input type="text" name="phone" value="<?= e($u['phone'] ?? '') ?>" placeholder="+12223334444">
    </div>
    <?php endif; ?>

    <div class="row">
      <button class="btn" type="submit">Save basics</button>
    </div>
  </form>

  <form method="post" class="card" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form" value="password">

    <h3 style="margin:0 0 10px">Change password</h3>
    <div class="row">
      <label>Current password</label>
      <input type="password" name="current_password" autocomplete="current-password">
    </div>
    <div class="row">
      <label>New password</label>
      <input type="password" name="new_password" autocomplete="new-password">
    </div>
    <div class="row">
      <label>Repeat new password</label>
      <input type="password" name="repeat_password" autocomplete="new-password">
    </div>
    <div class="row">
      <button class="btn" type="submit">Update password</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
