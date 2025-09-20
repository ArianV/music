<?php
// routes/account_settings.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$u = current_user();
$errors = [];
$saved  = false;
$flash  = ''; // one-off success message (e.g., cancel pending email)

/**
 * Small helpers (same rules you were using)
 */
if (!function_exists('normalize_handle')) {
  function normalize_handle(string $h): string {
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9_]/', '', $h);
    return $h;
  }
}
if (!function_exists('handle_is_valid')) {
  function handle_is_valid(?string $h): bool {
    if ($h === null) return false;
    return (bool) preg_match('/^[a-z0-9_]{3,20}$/', $h);
  }
}

// UI flags: only show hints AFTER user actually attempts a change
$attempted_username = false;
$attempted_email    = false;

ob_start();
?>

<div class="sets-wrap">
  <h1>Account settings</h1>

  <?php
  // --- POST actions ---
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';

    if ($which === 'basics') {
      $email_in  = trim($_POST['email'] ?? '');
      $handle_in = normalize_handle($_POST['handle'] ?? ($u['handle'] ?? ''));

      // What did the user actually try to change?
      $attempted_username = strcasecmp((string)($u['handle'] ?? ''), $handle_in) !== 0;
      $attempted_email    = strcasecmp((string)($u['email']  ?? ''), $email_in)   !== 0;

      // email validation
      if (!filter_var($email_in, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
      } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE lower(email)=lower(:e) AND id<>:me LIMIT 1');
        $st->execute([':e'=>$email_in, ':me'=>$u['id']]);
        if ($st->fetch()) $errors[] = 'That email is already in use.';
      }

      // handle validation
      if (!handle_is_valid($handle_in)) {
        $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores).';
      } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE lower(handle)=lower(:h) AND id<>:me LIMIT 1');
        $st->execute([':h'=>$handle_in, ':me'=>$u['id']]);
        if ($st->fetch()) $errors[] = 'That username is taken.';
      }

      // Rate limit: max 2 username changes in a rolling 14 days — only if attempting to change
      if (!$errors && $attempted_username) {
        $gate = can_change_username((int)$u['id']);
        if (!$gate['allowed']) {
          $when = $gate['next_at'] ? date('m-d-Y', strtotime($gate['next_at'])) : 'later';
          $errors[] = "You’ve reached the limit (2 changes per 14 days). Try again after $when.";
        }
      }

      if (!$errors) {
        $old_handle = $u['handle'] ?? null;

        // update handle/phone immediately
        $sets   = ['handle=:h'];
        $params = [':h'=>$handle_in, ':id'=>$u['id']];

        if (function_exists('table_has_column') && table_has_column(db(),'users','updated_at')) {
          $sets[] = 'updated_at=NOW()';
        }

        $sql = 'UPDATE users SET '.implode(', ',$sets).' WHERE id=:id';
        db()->prepare($sql)->execute($params);

        // Log username change if it actually changed
        if ($attempted_username) {
          record_username_change((int)$u['id'], $old_handle, $handle_in);
        }

        // If email changed, kick off "verify new email" flow (do NOT switch yet)
        if ($attempted_email) {
          begin_email_change((int)$u['id'], $email_in);
        }

        $saved = true;
        $u = current_user(); // refresh for display
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
        $hash   = password_hash($new, PASSWORD_DEFAULT);
        $sets   = ['password_hash=:ph'];
        $params = [':ph'=>$hash, ':id'=>$u['id']];
        if (function_exists('table_has_column') && table_has_column(db(),'users','updated_at')) {
          $sets[] = 'updated_at=NOW()';
        }
        $sql = 'UPDATE users SET '.implode(', ',$sets).' WHERE id=:id';
        db()->prepare($sql)->execute($params);
        $saved = true;
      }
    }

    if ($which === 'resend_verification') {
      if (!empty($u['pending_email'])) {
        begin_email_change((int)$u['id'], $u['pending_email']);
        $flash = 'Verification email re-sent.';
      }
    }

    if ($which === 'cancel_pending') {
      $st = db()->prepare('UPDATE users SET pending_email=NULL WHERE id=:id');
      $st->execute([':id'=>$u['id']]);
      $flash = 'Pending email change canceled.';
      $u = current_user(); // refresh
    }
  }
  ?>

  <?php
  // Notices (only when something actually happened)
  if ($saved && !$errors): ?>
    <div class="notice ok">Saved changes.</div>
  <?php endif; ?>
  <?php if ($flash): ?>
    <div class="notice ok"><?= e($flash) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="notice err"><?= e(implode("\n", $errors)) ?></div>
  <?php endif; ?>

  <?php if (!empty($u['pending_email'])): ?>
    <div class="notice" style="background:#fff7e6;border:1px solid #ffd27a">
      You’ve requested to change your email to <strong><?= e($u['pending_email']) ?></strong>.
      Please check your inbox and confirm. Your current email remains active until you confirm.
      <form method="post" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form" value="resend_verification">
        <button class="btn" style="height:28px;padding:0 10px;font-size:12px">Resend confirmation</button>
      </form>
      <form method="post" style="display:inline-block;margin-left:8px">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="form" value="cancel_pending">
        <button class="btn danger" style="height:28px;padding:0 10px;font-size:12px">Cancel</button>
      </form>
    </div>
  <?php endif; ?>

  <!-- Basics card (username/email/phone) -->
  <form method="post" class="card" autocomplete="on" style="margin-top: 10px !important;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form" value="basics">

    <div class="row" style="margin-bottom: 5px;">
      <label>Username</label>
      <div style="display:flex;gap:8px;align-items:center">
        <span class="inline-pill">@</span>
        <input type="text" name="handle" value="<?= e($u['handle'] ?? '') ?>" placeholder="yourname" style="max-width:240px">
      </div>
    </div>

    <div class="row">
      <label>Email</label>
      <input type="email" name="email" value="<?= e($u['email'] ?? '') ?>" placeholder="you@example.com">
      <?php if ($attempted_email): ?>
        <div class="muted" style="margin-top:6px">
          We’ll send a confirmation to the new email. Your current email stays active until you confirm.
        </div>
      <?php endif; ?>
    </div>

    <div class="row">
      <button class="btn" style="margin-top: 15px;" type="submit">Save</button>
    </div>
  </form>

  <!-- Password card -->
  <form method="post" class="card" autocomplete="off" style="margin-top: 25px !important;">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form" value="password">

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
      <button class="btn" style="margin-top: 15px;" type="submit">Change Password</button>
    </div>
  </form>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
