<?php
require_once __DIR__.'/../config.php';

/* --- facts --- */
$dir   = defined('UPLOAD_DIR') ? UPLOAD_DIR : '(undefined)';
$uri   = defined('UPLOAD_URI') ? UPLOAD_URI : '(undefined)';
$real  = @realpath($dir) ?: '(no realpath)';
$w     = is_writable($dir);
$df    = @disk_free_space($dir);
$dt    = @disk_total_space($dir);

/* --- create a “pin” file to survive redeploys --- */
@mkdir($dir, 0777, true);
$probe = rtrim($dir,'/').'/_persist_probe.txt';
$before = is_file($probe);
if (!$before) {
  @file_put_contents($probe, "created=".date('c')."\n");
}
$nowContent = @file_get_contents($probe) ?: '(unreadable)';

/* --- look for the mount in /proc/mounts --- */
$mountLine = '';
if (is_readable('/proc/mounts')) {
  foreach (file('/proc/mounts') as $ln) {
    if (str_contains($ln, rtrim($dir,'/'))) { $mountLine = trim($ln); break; }
  }
}

/* --- list a few files in uploads --- */
$list = [];
if (is_dir($dir)) {
  foreach (array_slice(scandir($dir, SCANDIR_SORT_DESCENDING) ?: [], 0, 15) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $dir.'/'.$f;
    $list[] = [
      'name'=>$f,
      'size'=> @filesize($p),
      'mtime'=> @date('c', @filemtime($p)),
      'url'=> rtrim($uri,'/').'/'.$f
    ];
  }
}

$title = 'Persistence probe';
ob_start(); ?>
<div class="card" style="max-width:920px;margin:24px auto;padding:18px">
  <h1 style="margin-top:0">Persistence probe</h1>
  <pre><?php
    echo "UPLOAD_DIR : ", e($dir), "\n";
    echo "realpath   : ", e($real), "\n";
    echo "UPLOAD_URI : ", e($uri), "\n";
    echo "writable   : ", $w ? 'YES' : 'NO', "\n";
    echo "disk       : total=", e((string)$dt), "  free=", e((string)$df), "\n";
    echo "probe file : ", e($probe), "\n";
    echo "existed?   : ", $before ? 'YES' : 'NO', "\n";
    echo "content    : ", e($nowContent), "\n";
    echo "mounts     : ", e($mountLine ?: '(no /proc/mounts hit for this path)'), "\n";
  ?></pre>

  <h3>Recent files</h3>
  <?php if ($list): ?>
    <ul>
      <?php foreach ($list as $it): ?>
        <li>
          <code><?= e($it['name']) ?></code>
          (<?= (int)$it['size'] ?> bytes, <?= e($it['mtime']) ?>)
          – <a href="<?= e($it['url']) ?>" target="_blank">open</a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="muted">No files found in uploads.</p>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
