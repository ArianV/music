<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $username = strtolower(preg_replace('/[^a-z0-9_]/i', '', $_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    // Strong validation + early return
    $errors = [];
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if ($password === '' || strlen($password) < 6) $errors[] = 'Password (6+ chars) is required.';

    if (!$errors) {
        // Uniqueness check
        $stmt = db()->prepare('SELECT 1 FROM users WHERE email = :email OR username = :username');
        $stmt->execute([':email' => $email, ':username' => $username]);
        if ($stmt->fetch()) {
            $errors[] = 'Email or username already in use.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Named parameters + RETURNING id (Postgres)
            $stmt = db()->prepare('
                INSERT INTO users (email, username, password_hash, created_at)
                VALUES (:email, :username, :hash, NOW())
                RETURNING id
            ');
            $stmt->execute([':email' => $email, ':username' => $username, ':hash' => $hash]);

            $_SESSION['user_id'] = (int) $stmt->fetchColumn();
            header('Location: ' . BASE_URL . 'dashboard');
            exit;
        }
    }

    // Surface first error in the UI
    if ($errors) $error = implode(' ', $errors);
}
$title = 'Register';
ob_start(); ?>
<div class="card">
  <h2>Create your account</h2>
  <?php if (!empty($error)): ?><p style="color:#f87171"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-row">
      <input type="email" name="email" placeholder="Email">
      <input type="text" name="username" placeholder="Username (letters, numbers, _)">
    </div>
    <div class="form-row">
      <input type="password" name="password" placeholder="Password">
    </div>
    <button>Register</button>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
