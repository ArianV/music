<?php
// routes/login.php
require_once __DIR__ . '/../config.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');   // don’t leak to users
ini_set('log_errors', '1');

if (current_user()) {
  header('Location: ' . asset('feed'));
  exit;
}

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $id = trim($_POST['id'] ?? '');
  $pw = (string)($_POST['password'] ?? '');

  if ($id === '' || $pw === '') {
    $err = 'Please enter your email/username and password.';
  } else {
    // login by email OR handle OR username (if present)
    $st = db()->prepare("
      SELECT *
      FROM users
      WHERE lower(email)  = lower(:id)
         OR lower(handle) = lower(:id)
      LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if ($u && password_verify($pw, (string)($u['password_hash'] ?? ''))) {
      $_SESSION['user_id'] = (int)$u['id'];
      header('Location: ' . asset('feed'));
      exit;
    }
    $err = 'Invalid credentials.';
  }
}

$title = 'Log in';
$head = <<<CSS
<style>
.auth-wrap { max-width: 480px; margin: 64px auto; padding: 0 16px; }
.auth-card { background:#111318; border:1px solid #1f2430; border-radius:14px; padding:20px; }
.auth-card h1 { margin:0 0 12px; }
.auth-card .row{ margin-bottom:12px; }
.auth-card label{ display:block; font-size:14px; color:#a1a1aa; margin-bottom:6px; }
.auth-card input[type="text"], .auth-card input[type="email"], .auth-card input[type="password"]{
  width:100%; box-sizing:border-box; background:#0f1217; border:1px solid #263142;
  color:#e5e7eb; border-radius:8px; padding:10px;
}
.auth-card .actions{ display:flex; gap:8px; align-items:center; }

/* make Log in and Sign up identical size */
.auth-card .btn,
.auth-card .btn-secondary{
  display:inline-flex; align-items:center; justify-content:center;
  height:36px; padding:0 12px; line-height:1; font-size:14px; border-radius:8px;
}
.btn { background:#2563eb; color:#fff; border:none; cursor:pointer; }
.btn:hover { filter: brightness(1.1); }
.btn-secondary { background:#1f2937; color:#e5e7eb; border:1px solid #2a3344; text-decoration:none; }
.btn-secondary:hover { background:#232e44; }

/* small error notice */
.notice.err{ margin:10px 0; padding:10px 12px; border-radius:8px;
  background:#3a0d0d; color:#fecaca; border:1px solid #7f1d1d; }
</style>
CSS;

ob_start(); ?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>Log in</h1>

    <?php if ($err): ?>
      <div class="notice err"><?= e($err) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="row">
        <label>Email or username</label>
        <input type="text" name="id" value="<?= e($_POST['id'] ?? '') ?>" autofocus>
      </div>

      <div class="row">
        <label>Password</label>
        <input type="password" name="password">
      </div>

      <div class="actions">
        <button type="submit" style="background-color: #7ef1c7 !important;" class="btn">Log in</button>
        <a class="btn-secondary" href="<?= e(asset('register')) ?>">Sign up</a>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
