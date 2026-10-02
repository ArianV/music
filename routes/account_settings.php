<?php
// routes/account_settings.php — username, email and password
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$u      = current_user();
$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $which = $_POST['form'] ?? '';

  if ($which === 'basics') {
    $email_in  = trim($_POST['email'] ?? '');
    $handle_in = trim($_POST['handle'] ?? '');
    $handle_in = $handle_in === '' ? (string)($u['handle'] ?? '') : normalize_handle($handle_in);

    // What did the user actually try to change?
    $changing_handle = strcasecmp((string)($u['handle'] ?? ''), $handle_in) !== 0;
    $changing_email  = strcasecmp((string)($u['email']  ?? ''), $email_in)   !== 0;

    if ($changing_email) {
      if (!filter_var($email_in, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
      } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE lower(email)=lower(:e) AND id<>:me LIMIT 1');
        $st->execute([':e'=>$email_in, ':me'=>$u['id']]);
        if ($st->fetch()) $errors[] = 'That email is already in use.';
      }
      // Your email is how you log in, so changing it needs your password.
      if (!password_verify((string)($_POST['current_password'] ?? ''), (string)$u['password_hash'])) {
        $errors[] = 'Enter your current password to change your email.';
      }
    }

    if ($changing_handle) {
      if (!handle_is_valid($handle_in)) {
        $errors[] = 'Username must be 3–20 characters (letters, numbers, underscores).';
      } else {
        $st = db()->prepare('SELECT 1 FROM users WHERE lower(handle)=lower(:h) AND id<>:me LIMIT 1');
        $st->execute([':h'=>$handle_in, ':me'=>$u['id']]);
        if ($st->fetch()) $errors[] = 'That username is taken.';
      }
      // Rate limit: see USERNAME_CHANGES_ALLOWED / USERNAME_CHANGE_DAYS in config.php
      if (!$errors) {
        $gate = can_change_username((int)$u['id']);
        if (!$gate['allowed']) {
          $when = $gate['next_at'] ? date('M j', strtotime($gate['next_at'])) : 'later';
          $errors[] = "You’ve reached the limit (" . USERNAME_CHANGES_ALLOWED . " changes per " . USERNAME_CHANGE_DAYS . " days). Try again after $when.";
        }
      }
    }

    if (!$errors && ($changing_handle || $changing_email)) {
      db()->prepare('UPDATE users SET handle=:h, email=:e, updated_at=NOW() WHERE id=:id')
          ->execute([':h'=>$handle_in, ':e'=>$changing_email ? $email_in : $u['email'], ':id'=>$u['id']]);
      if ($changing_handle) record_username_change((int)$u['id'], $u['handle'] ?? null, $handle_in);
      $saved = true;
      $u = current_user(true);
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
      db()->prepare('UPDATE users SET password_hash=:ph, updated_at=NOW() WHERE id=:id')
          ->execute([':ph'=>password_hash($new, PASSWORD_DEFAULT), ':id'=>$u['id']]);
      session_regenerate_id(true);
      $saved = true;
    }
  }
}

$title = 'Settings · PlugBio';
ob_start();
?>
<div class="sets-wrap">
  <div class="page-head"><div><h1>Settings</h1><p>Username, email and password</p></div></div>

  <?php if ($saved): ?><div class="notice ok">Saved changes.</div><?php endif; ?>
  <?php if ($errors): ?><div class="notice err"><?= e(implode("\n", $errors)) ?></div><?php endif; ?>

  <!-- Account card (username/email) -->
  <form method="post" class="card" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form" value="basics">
    <h3>Account</h3>

    <div class="row">
      <label for="handle">Username</label>
      <div class="input-prefix">
        <span class="inline-pill">@</span>
        <input type="text" id="handle" name="handle" value="<?= e($u['handle'] ?? '') ?>" placeholder="yourname">
      </div>
      <div class="hint">3–20 letters, numbers or underscores. You can change it <?= USERNAME_CHANGES_ALLOWED ?> times every <?= USERNAME_CHANGE_DAYS ?> days.</div>
    </div>

    <div class="row">
      <label for="email">Email</label>
      <input type="email" id="email" name="email" value="<?= e($u['email'] ?? '') ?>" placeholder="you@example.com" autocomplete="email">
      <div class="hint">You can log in with your email or your username.</div>
    </div>

    <div class="row">
      <label for="basics-password">Current password <span class="muted">(only needed to change your email)</span></label>
      <input type="password" id="basics-password" name="current_password" autocomplete="current-password">
    </div>

    <button class="btn btn-primary" type="submit">Save changes</button>
  </form>

  <!-- Password card -->
  <form method="post" class="card" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="form" value="password">
    <h3>Password</h3>

    <div class="row">
      <label for="cur">Current password</label>
      <input type="password" id="cur" name="current_password" autocomplete="current-password">
    </div>
    <div class="form-grid">
      <div class="row">
        <label for="new">New password</label>
        <input type="password" id="new" name="new_password" autocomplete="new-password" placeholder="8+ characters">
      </div>
      <div class="row">
        <label for="rep">Repeat new password</label>
        <input type="password" id="rep" name="repeat_password" autocomplete="new-password">
      </div>
    </div>
    <button class="btn" type="submit">Change password</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
