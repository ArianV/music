<?php
require_once __DIR__.'/../config.php';
require_auth();
csrf_check();

$u = current_user();
$to = $u['email'] ?? '';
$debug = [];
$ok = null;

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $ok = send_mail($to, 'PlugBio mail diag', '<b>Hello from PlugBio</b>', 'Hello from PlugBio', $debug);
}

$title='Email diagnostics'; 
$head = '<style>.wrap{max-width:820px;margin:32px auto;padding:0 16px}.card{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:18px}.muted{color:#9ca3af}.pre{white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;background:#0d0f14;border:1px solid #1f2430;border-radius:8px;padding:10px;}</style>';
ob_start(); ?>
<div class="wrap">
  <div class="card">
    <h1>Email diagnostics</h1>
    <p class="muted">To: <?= e($to) ?></p>

    <?php if ($ok!==null): ?>
      <p><?= $ok ? '✅ Sent (check your inbox/spam)' : '❌ Send failed' ?></p>
      <?php if ($debug): ?>
        <div class="pre"><?= e(implode("\n", $debug)) ?></div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn" type="submit">Send test email</button>
    </form>

    <div style="margin-top:12px" class="muted">
      <b>Checklist:</b>
      <ul>
        <li>BREVO_API_KEY set <i>or</i> SMTP_HOST/PORT/USER/PASS set</li>
        <li>MAIL_FROM is a verified sender/domain in Brevo</li>
        <li>Account has <b>Transactional (SMTP) enabled</b> in Brevo</li>
        <li>Check spam/promotions tab</li>
      </ul>
    </div>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
