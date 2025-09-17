<?php
require_once __DIR__.'/../config.php';

$dir = defined('UPLOAD_DIR') ? UPLOAD_DIR : '(undefined)';
$file = rtrim($dir,'/').'/_persist_probe.txt';
$existed = is_file($file);
if (!$existed) { @mkdir($dir, 0777, true); @file_put_contents($file, "created=".date('c')."\n"); }
$content = @file_get_contents($file) ?: '(unreadable)';

$mountLine = '';
if (is_readable('/proc/mounts')) {
  foreach (file('/proc/mounts') as $ln) { if (str_contains($ln, $dir)) { $mountLine = trim($ln); break; } }
}

$title='Persistence probe';
ob_start(); ?>
<div class="card" style="max-width:820px;margin:24px auto;padding:18px">
  <h1>Persistence probe</h1>
  <pre>UPLOAD_DIR : <?= e($dir) . "\n" ?>
realpath   : <?= e(@realpath($dir) ?: '(none)') . "\n" ?>
writable   : <?= is_writable($dir)?'YES':'NO' . "\n" ?>
probe file : <?= e($file) . "\n" ?>
existed?   : <?= $existed?'YES':'NO' . "\n" ?>
content    : <?= e($content) . "\n" ?>
mounts     : <?= e($mountLine ?: '(no /proc/mounts hit for this path)') . "\n" ?>
</pre>
  <p>If this page says <b>existed: YES</b> after a redeploy, persistence is working.</p>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
