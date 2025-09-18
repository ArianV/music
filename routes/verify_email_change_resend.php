<?php
require_once __DIR__.'/../config.php';
require_auth();
csrf_check();

$me = current_user();
if (!empty($me['pending_email'])) {
  begin_email_change((int)$me['id'], (string)$me['pending_email']);
}

$title = 'Verification sent';
$head = '<style>.wrap{max-width:680px;margin:40px auto;padding:0 16px}.card{background:#111318;border:1px solid #1f2430;border-radius:14px;padding:20px}</style>';
ob_start(); ?>
<div class="wrap">
  <div class="card">
    <h1>Verification resent</h1>
    <p>We sent a confirmation link to <b><?= e($me['pending_email'] ?? '') ?></b>.</p>
    <p><a class="btn" href="<?= e(asset('settings')) ?>">Back to settings</a></p>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
