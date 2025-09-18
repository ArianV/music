<?php
require_once __DIR__.'/../config.php';
require_auth();
csrf_check();

begin_email_verification((int)current_user()['id']);

$title = 'Verify email';
$head = '<style>.wrap{max-width:680px;margin:40px auto;padding:0 16px}.card{background:#111318;border:1px solid #1f2430;border-radius:14px;padding:20px}</style>';
ob_start(); ?>
<div class="wrap">
  <div class="card">
    <h1>Verification sent</h1>
    <p>We’ve sent a verification link to <b><?= e(current_user()['email'] ?? '') ?></b>. Please check your inbox.</p>
    <p class="muted">Didn’t get it? Check spam, or try again in a minute.</p>
    <p><a href="<?= e(asset('dashboard')) ?>" class="btn">Back to dashboard</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
