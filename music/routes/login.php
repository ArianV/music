<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $errors = [];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($password === '') $errors[] = 'Enter your password.';

    if (!$errors) {
        $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE lower(email) = lower(:email) LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
          session_regenerate_id(true);
          $_SESSION['user_id'] = (int)$row['id'];
          header('Location: ' . BASE_URL . 'dashboard');
          exit;
        } else {
            $error = 'Invalid credentials.';
        }
    } else {
        $error = implode(' ', $errors);
    }
}

$title = 'Login';
ob_start(); ?>
<div class="card">
  <h2>Login</h2>
  <?php if (!empty($error)): ?>
    <p style="color:#f87171"><?= e($error) ?></p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-row">
      <input type="email" name="email" placeholder="Email" required>
      <input type="password" name="password" placeholder="Password" required>
    </div>
    <button>Login</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
