<?php
// routes/login.php
require_once __DIR__ . '/../config.php';

if (current_user()) {
  header('Location: ' . asset('feed'));
  exit;
}

$err  = null;
$next = (string)($_POST['next'] ?? $_GET['next'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $id = trim($_POST['id'] ?? '');
  $pw = (string)($_POST['password'] ?? '');

  if ($id === '' || $pw === '') {
    $err = 'Please enter your email/username and password.';
  } else {
    // Throttle failed attempts per account (LOGIN_MAX_ATTEMPTS / LOGIN_LOCKOUT_MINUTES in config.php)
    $st = db()->prepare("SELECT count(*) FROM login_attempts
                         WHERE identifier = lower(:id) AND attempted_at > datetime('now', :window)");
    $st->execute([':id' => $id, ':window' => '-' . LOGIN_LOCKOUT_MINUTES . ' minutes']);
    if ((int)$st->fetchColumn() >= LOGIN_MAX_ATTEMPTS) {
      $err = 'Too many failed attempts. Please wait ' . LOGIN_LOCKOUT_MINUTES . ' minutes and try again.';
    } else {
      // login by email OR handle
      $st = db()->prepare("SELECT id, password_hash FROM users
                           WHERE lower(email) = lower(:id) OR lower(handle) = lower(:id)
                           LIMIT 1");
      $st->execute([':id' => $id]);
      $u = $st->fetch();

      if ($u && password_verify($pw, (string)$u['password_hash'])) {
        db()->prepare('DELETE FROM login_attempts WHERE identifier = lower(:id)')->execute([':id' => $id]);
        login_user((int)$u['id']);
        header('Location: ' . safe_next($next, 'feed'));
        exit;
      }
      db()->prepare('INSERT INTO login_attempts (identifier) VALUES (lower(:id))')->execute([':id' => $id]);
      $err = 'Invalid credentials.';
    }
  }
}

$title = 'Log in · PlugBio';
ob_start(); ?>
<div class="auth">
  <div class="card">
    <h1>Welcome back</h1>
    <p class="sub">Log in to manage your pages.</p>

    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="row">
        <label for="id">Email or username</label>
        <input type="text" id="id" name="id" value="<?= e($_POST['id'] ?? '') ?>" autocomplete="username" autofocus>
      </div>
      <div class="row">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password">
      </div>

      <button type="submit" class="btn btn-primary">Log in</button>
    </form>
    <p class="alt">New here? <a href="<?= e(asset('register')) ?>">Create an account</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
