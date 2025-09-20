<?php
// routes/account_settings.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$u = current_user();
$errors = [];
$saved  = false;
$flash  = ''; // one-off success message (e.g., cancel pending email)

// --- Small helpers (same rules you’ve been using) ---
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
if (!function_exists('clean_phone')) {
  function clean_phone($p) {
    if ($p === null || $p === '') return null;
    $p = preg_replace('/\D+/', '', (string)$p);
    return $p ?: null;
  }
}

ob_start();
?>

<div class="sets-wrap">
  <h1>Account settings</h1>

  <?php
  // Notices
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

  <?php
  // --- POST actions ---
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = $_POST['form'] ?? '';

    if ($which === 'basics') {
      $email  = trim($_POST['email'] ?? '');
      $handle = normalize_handle($_POST['handle'] ?? ($u['handle'] ?? ''));
      $old_handle = $u['handle'] ?? null; // capture for logging after save
      $phone  = clean_phone($_POST['phone'] ?? null);

      // email validation
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
      } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE lower(email)=lower(:e) AND id<>:me LIMIT 1');
        $st->execute([':e'=>$email, ':me'=>$u['id']]);
        if ($st->fetch()) $errors[] = 'That email is already in use.';
      }

      // handle validation
      if (!handle_is_valid($handle)) {
        $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores).';
      } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE lower(handle)=lower(:h) AND id<>:me LIMIT 1');
        $st->execute([':h'=>$handle, ':me'=>$u['id']]);
        if ($st->fetch()) $errors[] = 'That username is taken.';
      }

      // Rate limit: max 2 username changes in a rolling 14 days, only if actually changing
      $handle_changed = strcasecmp((string)($u['handle'] ?? ''), $handle) !== 0;
      if (!$errors && $handle_changed) {
        $gate = can_change_username((int)$u['id']);
        if (!$gate['allowed']) {
          $when = $gate['next_at'] ? date('Y-m-d H:i', strtotime($gate['next_at'])) : 'later';
          $errors[] = "You’ve reached the limit (2 changes per 14 days). Try again after $when.";
        }
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

        // Log username change if it actually changed
        if (!empty($handle_changed) && $handle_changed) {
          record_username_change((int)$u['id'], $old_handle, $handle);
        }

        // If email changed, kick off "verify new email" flow (do NOT switch yet)
        if (strcasecmp($email, (string)($u['email'] ?? '')) !== 0) {
          // requires begin_email_change() helper in config.php
          begin_email_change((int)$u['id'], $email);
        }

        $saved = true;
        $u = current_user(); // refresh
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

  <!-- Basics card (username/email/phone) -->
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
      <?php
        // Remaining username changes / cooldown hint
        $info = can_change_username((int)$u['id']);
        $c = db()->prepare("SELECT count(*) FROM username_changes WHERE user_id=:uid AND changed_at >= now() - INTERVAL '14 days'");
        $c->execute([':uid'=>$u['id']]);
        $used = (int)$c->fetchColumn();
        $left = max(0, 2 - $used);
        if ($info['allowed']) {
          echo '<div class="muted" style="margin-top:4px">You can change your username ' . $left . ' more time(s) in the next 14 days.</div>';
        } else {
          echo '<div class="muted" style="margin-top:4px">Limit reached. Try again after ' . e(date('Y-m-d H:i', strtotime($info['next_at']))) . '.</div>';
        }
      ?>
    </div>

    <div class="row">
      <label>Email</label>
      <input type="email" name="email" value="<?= e($u['email'] ?? '') ?>" placeholder="you@example.com">
      <div class="muted" style="margin-top:6px">We’ll send a confirmation to the new email. Your current email stays active until you confirm.</div>
    </div>

    <?php if (function_exists('table_has_column') && table_has_column(db(),'users','phone')): ?>
    <div class="row">
      <label>Phone</label>
      <input type="tel" name="phone" value="<?= e($u['phone'] ?? '') ?>" placeholder="(555) 555-5555">
      <div class="muted" style="margin-top:6px">Optional. Numbers only; we’ll clean up formatting.</div>
    </div>
    <?php endif; ?>

    <div class="row">
      <button class="btn" type="submit">Save</button>
    </div>
  </form>

  <!-- Password card -->
  <form method="post" class="card" autocomplete="off">
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
      <button class="btn" type="submit">Change Password</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
