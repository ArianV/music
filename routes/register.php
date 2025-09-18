<?php
// routes/register.php
require_once __DIR__ . '/../config.php';
csrf_check();

if (current_user()) { header('Location: ' . asset('dashboard')); exit; }
$pdo = db();

function handleify(string $s, int $maxLen=30): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  if (function_exists('iconv')) { $t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if($t!==false) $s=$t; }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = preg_replace('/-+/', '-', $s);
  $s = trim($s, '-');
  if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
  return $s ?: 'user';
}

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $email    = trim($_POST['email'] ?? '');
  $pass     = (string)($_POST['password'] ?? '');
  $pass2    = (string)($_POST['password2'] ?? '');

  if ($username === '' || strlen($username) < 2) $err = 'Please enter a username (min 2 chars).';
  if (!$err && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Please enter a valid email.';
  if (!$err && strlen($pass) < 6) $err = 'Password must be at least 6 characters.';
  if (!$err && $pass !== $pass2) $err = 'Passwords do not match.';

  // email unique?
  if (!$err) {
    $st = $pdo->prepare('SELECT 1 FROM users WHERE lower(email)=lower(:e) LIMIT 1');
    $st->execute([':e'=>$email]);
    if ($st->fetch()) $err = 'That email is already registered.';
  }

  // build unique handle from username
  $display = $username;
  $handle  = handleify($username);
  if (!$err && table_has_column($pdo,'users','handle')) {
    $base = $handle; $n = 1;
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE lower(handle)=lower(:h) LIMIT 1');
    while (true) {
      $chk->execute([':h'=>$handle]);
      if (!$chk->fetch()) break;
      $n++; $handle = $base . '-' . $n;
      if ($n > 200) { $err = 'Could not generate a unique handle.'; break; }
    }
  }

  if (!$err) {
    $fields = ['email'];
    $values = [':email'];
    $params = [':email'=>$email];

    // password hash
    $phash = password_hash($pass, PASSWORD_DEFAULT);
    if (table_has_column($pdo,'users','password_hash')) {
      $fields[]='password_hash'; $values[]=':phash'; $params[':phash']=$phash;
    }

    // display name and handle
    if (table_has_column($pdo,'users','display_name')) { $fields[]='display_name'; $values[]=':d'; $params[':d']=$display; }
    if (table_has_column($pdo,'users','handle'))       { $fields[]='handle';       $values[]=':h'; $params[':h']=$handle; }

    // default avatar (points to /assets/avatar-default.svg)
    $defaultAvatar = asset('assets/avatar-default.svg');
    if (table_has_column($pdo,'users','avatar_uri')) { $fields[]='avatar_uri'; $values[]=':a'; $params[':a']=$defaultAvatar; }

    // start private
    if (table_has_column($pdo,'users','profile_public')) { $fields[]='profile_public'; $values[]='false'; }

    if (table_has_column($pdo,'users','created_at')) { $fields[]='created_at'; $values[]='NOW()'; }
    if (table_has_column($pdo,'users','updated_at')) { $fields[]='updated_at'; $values[]='NOW()'; }

    $sql = 'INSERT INTO users ('.implode(',',$fields).') VALUES ('.implode(',',$values).') RETURNING id';
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    $id = (int)($st->fetchColumn() ?: 0);

    begin_email_verification((int)$id);

    if ($id > 0) {
      $_SESSION['user_id'] = $id;
      header('Location: ' . asset('dashboard'));
      exit;
    } else {
      $err = 'Could not create your account. Please try again.';
    }
  }
}

ob_start(); ?>
<div class="card" style="max-width:560px;margin:24px auto;padding:20px">
  <h1 style="margin-top:0">Create account</h1>
  <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="row">
      <label>Username</label>
      <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" required minlength="2" placeholder="lilfoaf">
    </div>

    <div class="row">
      <label>Email</label>
      <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="you@example.com">
    </div>

    <div class="row">
      <label>Password</label>
      <input type="password" name="password" required minlength="6" placeholder="••••••••">
    </div>

    <div class="row">
      <label>Confirm password</label>
      <input type="password" name="password2" required minlength="6" placeholder="••••••••">
    </div>

    <div class="row" style="padding-top: 20px;">
      <button class="btn btn-primary" type="submit">Create account</button>
    </div>

  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
