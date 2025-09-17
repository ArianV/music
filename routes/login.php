<?php
// routes/login.php
require_once __DIR__ . '/../config.php';
csrf_check();

$pdo = db();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $login    = trim($_POST['login'] ?? '');   // email or handle/username
  $password = (string)($_POST['password'] ?? '');

  if ($login === '' || $password === '') {
    $errors[] = 'Email/username and password are required.';
  } else {
    // Build WHERE that matches any available identity column
    $whereParts = [];
    $params = [':v' => strtolower($login)];

    if (table_has_column($pdo, 'users', 'email'))    $whereParts[] = 'lower(email) = :v';
    if (table_has_column($pdo, 'users', 'handle'))   $whereParts[] = 'lower(handle) = :v';
    if (table_has_column($pdo, 'users', 'username')) $whereParts[] = 'lower(username) = :v';

    if (!$whereParts) {
      $errors[] = 'Login is not configured (no email/handle/username column).';
    } else {
      // Determine which password column we have
      $pwdCol = null;
      if (table_has_column($pdo, 'users', 'password_hash')) $pwdCol = 'password_hash';
      elseif (table_has_column($pdo, 'users', 'password'))  $pwdCol = 'password';

      if (!$pwdCol) {
        $errors[] = 'Login is not configured (no password column).';
      } else {
        $cols = "id, " . $pwdCol . " AS pwd";
        // grab a couple of optional fields for session niceties
        if (table_has_column($pdo, 'users', 'display_name')) $cols .= ", display_name";
        if (table_has_column($pdo, 'users', 'handle'))       $cols .= ", handle";

        $sql = "SELECT $cols FROM users WHERE (" . implode(' OR ', $whereParts) . ") LIMIT 1";
        $st  = $pdo->prepare($sql);
        $st->execute($params);
        $u = $st->fetch();

        if (!$u) {
          $errors[] = 'Invalid credentials.';
        } else {
          $ok = false;
          // If stored as bcrypt/argon/etc.
          if (strlen($u['pwd'] ?? '') > 0 && preg_match('/^\$2[ayb]\$|\$argon2i|\$argon2id/', $u['pwd'])) {
            $ok = password_verify($password, $u['pwd']);
          } else {
            // fallback: plain (not recommended)
            $ok = hash_equals((string)$u['pwd'], $password);
          }

          if ($ok) {
            $_SESSION['user_id'] = (int)$u['id'];
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
          } else {
            $errors[] = 'Invalid credentials.';
          }
        }
      }
    }
  }
}

// ----- view -----
$title = 'Log in';
ob_start(); ?>
<div class="card" style="max-width:420px;margin:40px auto;padding:24px">
  <h1 class="title" style="margin-top:0">Log in</h1>

  <?php if ($errors): ?>
    <div class="notice err" style="border:1px solid #7f1d1d;background:#1b1212;color:#fca5a5;padding:10px 12px;border-radius:10px;margin-bottom:12px">
      <?= e(implode(' ', $errors)) ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="row">
      <label class="small">Email or username</label>
      <input type="text" name="login" required autocomplete="username">
    </div>
    <div class="row">
      <label class="small">Password</label>
      <input type="password" name="password" required autocomplete="current-password">
    </div>
    <div class="row">
      <button type="submit" class="btn btn-primary">Log in</button>
      <a class="btn" href="<?= e(asset('register')) ?>" style="margin-left:8px">Sign up</a>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
