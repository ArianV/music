<?php
require_once __DIR__.'/../config.php'; require_auth(); csrf_check();
$ok = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $to = current_user()['email'] ?? '';
  $ok = send_mail($to, 'PlugBio SMTP test', '<b>Hello from PlugBio</b>', 'Hello from PlugBio');
}
$title='Email test'; $head='<style>.wrap{max-width:680px;margin:40px auto;padding:0 16px}.card{background:#111318;border:1px solid #1f2430;border-radius:14px;padding:20px}</style>';
ob_start(); ?>
<div class="wrap"><div class="card">
  <h1>SMTP test</h1>
  <?php if ($ok!==null): ?>
    <p><?= $ok ? '✅ Sent. Check your inbox.' : '❌ Send failed.' ?></p>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn" type="submit">Send test to my email</button>
  </form>
</div></div>
<?php $content=ob_get_clean(); require __DIR__.'/../views/layout.php';
