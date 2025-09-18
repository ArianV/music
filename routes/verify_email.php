<?php
require_once __DIR__.'/../config.php';

$uid   = (int)($_GET['uid'] ?? 0);
$token = (string)($_GET['token'] ?? '');

$ok = false; $msg = 'Invalid or expired link.';

if ($uid > 0 && $token !== '') {
  $st = db()->prepare('SELECT id, verify_token_hash, verify_token_expires FROM users WHERE id=:id LIMIT 1');
  $st->execute([':id'=>$uid]);
  if ($u = $st->fetch()) {
    $hash = hash('sha256', $token);
    $notExpired = !empty($u['verify_token_expires']) && (new DateTime($u['verify_token_expires'])) > new DateTime();
    if (hash_equals((string)$u['verify_token_hash'], $hash) && $notExpired) {
      $upd = db()->prepare('UPDATE users SET email_verified_at=NOW(), verify_token_hash=NULL, verify_token_expires=NULL WHERE id=:id');
      $upd->execute([':id'=>$uid]);
      $ok = true; $msg = 'Your email has been verified. You’re all set!';
      // If it’s you, refresh session user
      if (!empty($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $uid) {
        // nothing else needed; current_user() re-reads from DB on each call
      }
    }
  }
}

$title = 'Email verification';
$head = '<style>.wrap{max-width:680px;margin:40px auto;padding:0 16px}.card{background:#111318;border:1px solid #1f2430;border-radius:14px;padding:20px}</style>';
ob_start(); ?>
<div class="wrap">
  <div class="card">
    <h1><?= e($ok ? 'Verified 🎉' : 'Verification error') ?></h1>
    <p><?= e($msg) ?></p>
    <p><a href="<?= e(asset('dashboard')) ?>" class="btn">Go to dashboard</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
