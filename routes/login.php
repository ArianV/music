<?php
// routes/login.php
require_once __DIR__ . '/../config.php';
csrf_check();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email    = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    $error = 'Please provide a valid email and password.';
  } else {
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM users WHERE email = :e');
    $st->execute([':e' => $email]);
    $u = $st->fetch();

    if (!$u || empty($u['password_hash']) || !password_verify($password, (string)$u['password_hash'])) {
      $error = 'Invalid email or password.';
    } else {
      $_SESSION['user_id'] = (int)$u['id'];
      header('Location: ' . BASE_URL . 'dashboard');
      exit;
    }
  }
}

ob_start();
?>
<h1>Log in</h1>

<?php if ($error): ?>
  <div style="background:#3b1f22;color:#fca5a5;border:1px solid #7f1d1d;padding:10px;border-radius:8px;margin-bottom:12px;">
    <?= e($error) ?>
  </div>
<?php endif; ?>

<form method="post" style="background:#111318;border:1px solid #1f2430;border-radius:12px;padding:20px;max-width:420px;">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="row" style="margin-bottom:12px;">
    <label style="display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px">Email</label>
    <input type="email" name="email" required autocomplete="email"
           style="width:100%;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px">
  </div>
  <div class="row" style="margin-bottom:12px;">
    <label style="display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px">Password</label>
    <input type="password" name="password" required autocomplete="current-password"
           style="width:100%;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px">
  </div>
  <button type="submit" class="btn" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer">Log in</button>
  <div style="margin-top:10px">
    <a href="<?= e(BASE_URL) ?>register" style="color:#93c5fd;text-decoration:none">Need an account? Register</a>
  </div>
</form>
<?php
$content = ob_get_clean();
$title = 'Login · Music Landing';
require __DIR__ . '/../views/layout.php';
