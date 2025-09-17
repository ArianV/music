<?php
require_once __DIR__.'/../config.php';

function sizeToBytes($v){ $u=strtoupper(substr($v,-1)); $n=(int)$v; return $u==='G'?$n*1024*1024*1024:($u==='M'?$n*1024*1024:($u==='K'?$n*1024:$n)); }
$checks = [
  'file_uploads'      => ini_get('file_uploads'),
  'post_max_size'     => ini_get('post_max_size'),
  'upload_max_filesize'=> ini_get('upload_max_filesize'),
  'max_file_uploads'  => ini_get('max_file_uploads'),
  'memory_limit'      => ini_get('memory_limit'),
  'sys_get_temp_dir'  => sys_get_temp_dir(),
  'UPLOAD_DIR'        => defined('UPLOAD_DIR') ? UPLOAD_DIR : '(not defined)',
  'UPLOAD_URI'        => defined('UPLOAD_URI') ? UPLOAD_URI : '(not defined)',
];

$dir_exists = is_dir(UPLOAD_DIR);
$dir_writable = $dir_exists && is_writable(UPLOAD_DIR);

// write test
$write_test = null;
if ($dir_exists) {
  $f = rtrim(UPLOAD_DIR,'/').'/__write_test_'.bin2hex(random_bytes(3)).'.txt';
  $write_test = @file_put_contents($f, "ok ".date('c')) !== false ? "OK ($f)" : 'FAIL';
}

$upload_result = null;
$file_debug = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $file_debug = $_FILES['cover'] ?? null;
  $errMap = [
    0=>'UPLOAD_ERR_OK',1=>'UPLOAD_ERR_INI_SIZE',2=>'UPLOAD_ERR_FORM_SIZE',3=>'UPLOAD_ERR_PARTIAL',
    4=>'UPLOAD_ERR_NO_FILE',6=>'UPLOAD_ERR_NO_TMP_DIR',7=>'UPLOAD_ERR_CANT_WRITE',8=>'UPLOAD_ERR_EXTENSION'
  ];
  if (!empty($_FILES['cover']['name'])) {
    $e = (int)($_FILES['cover']['error'] ?? 0);
    if ($e !== UPLOAD_ERR_OK) {
      $upload_result = 'PHP error: '.$errMap[$e]." ($e)";
    } else {
      $safe = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/','',$_FILES['cover']['name']);
      $dest = rtrim(UPLOAD_DIR,'/').'/'.$safe;
      if (is_uploaded_file($_FILES['cover']['tmp_name']) && @move_uploaded_file($_FILES['cover']['tmp_name'], $dest)) {
        $upload_result = 'Saved OK → '.$dest.'  ·  URL: '.rtrim(UPLOAD_URI,'/').'/'.$safe;
      } else {
        $upload_result = 'move_uploaded_file failed (permissions/path?)';
      }
    }
  } else {
    $upload_result = 'No file received';
  }
}

$title='Upload diagnostics';
ob_start(); ?>
<div class="card" style="max-width:820px;margin:24px auto;padding:18px">
  <h1 class="title" style="margin-top:0">Upload diagnostics</h1>

  <h3>Environment</h3>
  <pre><?php foreach($checks as $k=>$v) echo e(str_pad($k,20)).": ".e((string)$v)."\n"; ?></pre>
  <p>UPLOAD_DIR exists: <?= $dir_exists?'✅':'❌' ?> · writable: <?= $dir_writable?'✅':'❌' ?> · write test: <?= e((string)$write_test) ?></p>

  <h3>Try an upload</h3>
  <form method="post" enctype="multipart/form-data">
    <input type="file" name="cover" accept="image/*" required>
    <button class="btn btn-primary" type="submit">Upload test</button>
  </form>

  <?php if ($upload_result): ?>
    <div style="margin-top:12px" class="notice <?= str_starts_with($upload_result,'Saved')?'ok':'err' ?>">
      <?= e($upload_result) ?>
    </div>
  <?php endif; ?>

  <?php if ($file_debug): ?>
    <h3>$_FILES dump</h3>
    <pre><?= e(print_r($file_debug, true)) ?></pre>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__.'/../views/layout.php';
