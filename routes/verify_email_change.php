<?php
require_once __DIR__.'/../config.php';

$uid   = (int)($_GET['uid'] ?? 0);
$token = (string)($_GET['token'] ?? '');

$ok = false; $msg = 'Invalid or expired link.';

if ($uid > 0 && $token !== '') {
  $st = db()->prepare('SELECT id, pending_email, pending_email_token_hash, pending_email_expires FROM users WHERE id=:id LIMIT 1');
  $st->execute([':id'=>$uid]);
  if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
    $hash = hash('sha256', $token);
    $notExpired = !empty($u['pending_email_expires']) && (new DateTime($u['pending_email_expires'])) > new DateTime();
    if ($u['pending_email'] && $notExpired && hash_equals((string)$u['pending_email_token_hash'], $hash)) {
      // Promote pending -> primary and mark verified
      $upd = db()->prepare('UPDATE users
        SET email=:e, email_verified_at=NOW(),
            pending_email=NULL, pending_email_token_hash=NULL, pending_email_expires=NULL
        WHERE id=:id');
      try {
        $upd->execute([':e'=>$u['pending_email'], ':id'=>$uid]);
        $ok = true; $msg = 'Your email has been updated and verified. 🎉';
      } catch (Throwable $ex) {
        $msg = 'That email may already be in use. Please try a different address.';
      }
    }
  }
}

$title = 'Confirm new email';
$head = '<style>.wrap{max-width:680px;margin:40px auto;padding:0 16px}.card{background:#111318;border:1px solid #1f2430;border-radius:14px;padding:20px}</style>';
ob_start(); ?>
<div class="wrap">
  <div class="card">
    <h1><?= e($ok ? 'Email updated' : 'Verification error') ?></h1>
    <p><?= e($msg) ?></p>
    <p><a class="btn" href="<?= e(asset('settings')) ?>">Back to settings</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
