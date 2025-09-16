<?php
// routes/register.php
require_once __DIR__ . '/../config.php';
csrf_check();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name     = trim($_POST['name'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $password = (string)($_POST['password'] ?? '');

  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    $error = 'Please provide a valid email and password.';
  } else {
    $pdo = db();

    // Unique email?
    $st = $pdo->prepare('SELECT 1 FROM users WHERE email = :e');
    $st->execute([':e' => $email]);
    if ($st->fetch()) {
      $error = 'That email is already registered.';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);

      $cols = ['email','password_hash'];
      $vals = [':email',':hash'];
      $par  = [':email'=>$email, ':hash'=>$hash];

      if (table_has_column($pdo, 'users', 'name'))       { $cols[]='name';       $vals[]=':name';       $par[':name']=$name; }
      if (table_has_column($pdo, 'users', 'created_at')) { $cols[]='created_at'; $vals[]='NOW()'; }

      $sql = 'INSERT INTO users ('.implode(',', $cols).') VALUES ('.implode(',', $vals).') RETURNING id';
      $ins = $pdo->prepare($sql);
      $ins->execute($par);
      $row = $ins->fetch();

      $_SESSION['user_id'] = (int)$row['id'];
      header('Location: ' . BASE_URL . 'dashboard');
      exit;
    }
  }
}

ob_start();
?>
<h1>Create your account</h1>

<?php if ($error): ?>
  <div style="background:#3b1f22;color:#fca5a5;border:1px solid #7f1d1d;padding:10px;border-radius:8px;margin-bottom:12px;">
    <?= e($error) ?>
  </div>
<?php endif; ?>

<form method="post" style="background:#111318;border:1px solid #1f2430;border-radius:12px;padding:20px;max-width:420px;">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <div class="row" style="margin-bottom:12px;">
    <label style="display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px">Username</label>
    <input type="text" name="name" required autocomplete="name"
           value="<?= e($_POST['name'] ?? '') ?>"
           style="width:100%;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px">
  </div>
  <div class="row" style="margin-bottom:12px;">
    <label style="display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px">Email</label>
    <input type="email" name="email" required autocomplete="email"
           value="<?= e($_POST['email'] ?? '') ?>"
           style="width:100%;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px">
  </div>
  <div class="row" style="margin-bottom:12px;">
    <label style="display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px">Password</label>
    <input type="password" name="password" required autocomplete="new-password"
           style="width:100%;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px">
  </div>
  <button type="submit" class="btn" style="background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer">Sign up</button>
  <div style="margin-top:10px">
    <a href="<?= e(BASE_URL) ?>login" style="color:#93c5fd;text-decoration:none">Already have an account? Log in</a>
  </div>
</form>
<?php
$content = ob_get_clean();
$title = 'Register · Music Landing';
require __DIR__ . '/../views/layout.php';
