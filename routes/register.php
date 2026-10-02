<?php
// routes/register.php
require_once __DIR__ . '/../config.php';
csrf_check();

if (current_user()) { header('Location: ' . asset('dashboard')); exit; }
$pdo = db();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $handle   = strtolower($username);
  $email    = trim($_POST['email'] ?? '');
  $pass     = (string)($_POST['password'] ?? '');
  $pass2    = (string)($_POST['password2'] ?? '');

  if (!handle_is_valid($handle)) $err = 'Username must be 3–20 characters (letters, numbers, underscores).';
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Please enter a valid email.';
  elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
  elseif ($pass !== $pass2) $err = 'Passwords do not match.';

  if (!$err) {
    $st = $pdo->prepare('SELECT lower(email) = lower(:e) AS email_taken FROM users
                         WHERE lower(email) = lower(:e) OR lower(handle) = :h LIMIT 1');
    $st->execute([':e' => $email, ':h' => $handle]);
    if ($row = $st->fetch()) {
      $err = $row['email_taken'] ? 'That email is already registered.' : 'That username is taken.';
    }
  }

  if (!$err) {
    $st = $pdo->prepare('INSERT INTO users (email, password_hash, display_name, handle, avatar_uri, profile_public)
                         VALUES (:email, :ph, :d, :h, :a, 0)');
    $st->execute([
      ':email' => $email,
      ':ph'    => password_hash($pass, PASSWORD_DEFAULT),
      ':d'     => $username,
      ':h'     => $handle,
      ':a'     => asset('assets/avatar-default.svg'),
    ]);
    $id = (int)$pdo->lastInsertId();

    login_user($id);
    header('Location: ' . asset('dashboard'));
    exit;
  }
}

$title = 'Create account · PlugBio';
ob_start(); ?>
<div class="auth">
  <div class="card">
    <h1>Create your account</h1>
    <p class="sub">Free, and your first page takes a minute.</p>

    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="row">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" value="<?= e($_POST['username'] ?? '') ?>" required minlength="3" maxlength="20" pattern="[A-Za-z0-9_]{3,20}" title="3–20 letters, numbers or underscores" placeholder="lilfoaf" autocomplete="username">
        <div class="hint">Your profile will live at /u/username</div>
      </div>
      <div class="row">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" required placeholder="you@example.com" autocomplete="email">
      </div>
      <div class="form-grid">
        <div class="row">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="8" placeholder="8+ characters" autocomplete="new-password">
        </div>
        <div class="row">
          <label for="password2">Confirm</label>
          <input type="password" id="password2" name="password2" required minlength="8" placeholder="Repeat it" autocomplete="new-password">
        </div>
      </div>

      <button class="btn btn-primary" type="submit">Create account</button>
    </form>
    <p class="alt">Already have an account? <a href="<?= e(asset('login')) ?>">Log in</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
