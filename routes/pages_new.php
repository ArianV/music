<?php
// routes/pages_new.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/* ---------- local helpers (intentionally NOT guarded) ---------- */
// Robust ASCII slugifier (no dependence on any global slugify)
function ml_slugify(string $s, int $maxLen = 80): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
  if (function_exists('iconv')) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
  }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);  // non alnum => dash
  $s = preg_replace('/-+/', '-', $s);           // collapse dashes
  $s = trim($s, '-');                           // trim edges
  if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
  return $s !== '' ? $s : 'page';
}
// Unique slug per user_id; can exclude a page id during edits
function ml_unique_page_slug(PDO $pdo, int $userId, string $title, string $artist = '', ?int $excludeId = null): string {
  $base = ml_slugify(trim(($artist ? "$artist " : '') . $title), 72);
  $sql = "SELECT LOWER(slug) AS s FROM pages WHERE user_id = :uid AND slug ILIKE :like";
  $par = [':uid' => $userId, ':like' => $base.'%'];
  if ($excludeId) { $sql .= " AND id <> :id"; $par[':id'] = $excludeId; }
  $st = $pdo->prepare($sql); $st->execute($par);
  $taken = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 's'));
  if (!isset($taken[$base])) return $base;
  for ($i=2; $i<=200; $i++) { $try = $base.'-'.$i; if (!isset($taken[$try])) return $try; }
  return $base.'-'.bin2hex(random_bytes(2));
}
// Parse "Label | URL" lines to JSON
function parse_links_to_json(?string $raw): string {
  $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)$raw))));
  $out = [];
  foreach ($lines as $line) {
    $label = null; $url = $line;
    if (strpos($line, '|') !== false) { [$label, $url] = array_map('trim', explode('|', $line, 2)); }
    if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
    $out[] = ['label' => $label ?: null, 'url' => $url];
  }
  return json_encode($out, JSON_UNESCAPED_SLASHES);
}
// Optional remote cover fetch
function download_image_to_uploads(string $url): ?string {
  if (!defined('UPLOAD_DIR') || !defined('UPLOAD_URI')) return null;
  $bin = function_exists('http_get') ? http_get($url, 6000) : @file_get_contents($url);
  if (!$bin) return null;
  $ext = '.jpg';
  $pathPart = parse_url($url, PHP_URL_PATH) ?? '';
  if (preg_match('/\.(png|webp|jpe?g)$/i', $pathPart, $m)) $ext='.'.strtolower($m[1]);
  $name='cover_'.time().'_'.bin2hex(random_bytes(4)).$ext;
  $full=rtrim(UPLOAD_DIR,'/').'/'.$name;
  if (@file_put_contents($full, $bin) === false) return null;
  return rtrim(UPLOAD_URI,'/').'/'.$name;
}

/* ---------- POST (create) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title        = trim($_POST['title']  ?? '');
  $artistInput  = trim($_POST['artist'] ?? '');
  $links_raw    = $_POST['links_raw']  ?? '';
  $auto_img_url = trim($_POST['auto_image_url'] ?? '');
  $published    = isset($_POST['published']) ? 1 : 0;
  if ($title === '') { http_response_code(422); exit('Title is required'); }

  $pdo = db();
  $artist = $artistInput !== '' ? $artistInput : 'Unknown Artist';
  $links_json = parse_links_to_json($links_raw);

  // Cover handling
  $cover_uri = null;
  if (!empty($_FILES['cover']['name'] ?? '')) {
    $safeName = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['cover']['name']);
    $target   = rtrim(UPLOAD_DIR, '/').'/'.$safeName;
    if (is_uploaded_file($_FILES['cover']['tmp_name'] ?? '') && @move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
      $cover_uri = rtrim(UPLOAD_URI, '/').'/'.$safeName;
    }
  }
  if (!$cover_uri && $auto_img_url) {
    $saved = download_image_to_uploads($auto_img_url);
    if ($saved) $cover_uri = $saved;
  }

  // Build INSERT
  $cols=['user_id','title','links_json','published','created_at','updated_at'];
  $vals=[':uid',':title',':links_json',':pub','NOW()','NOW()'];
  $par = [':uid'=>$user['id'], ':title'=>$title, ':links_json'=>$links_json, ':pub'=>$published];

  if (table_has_column($pdo,'pages','artist_name')) { $cols[]='artist_name'; $vals[]=':artist'; $par[':artist']=$artist; }
  if (table_has_column($pdo,'pages','artist'))      { $cols[]='artist';      $vals[]=':artist2'; $par[':artist2']=$artist; }

  if ($cover_uri) {
    foreach (['cover_uri','cover_url','cover_image'] as $c) {
      if (table_has_column($pdo,'pages',$c)) { $cols[]=$c; $vals[]=':cover'; $par[':cover']=$cover_uri; break; }
    }
  }

  if (table_has_column($pdo,'pages','slug')) {
    $par[':slug'] = ml_unique_page_slug($pdo, (int)$user['id'], $title, $artist, null);
    $cols[]='slug'; $vals[]=':slug';
  }

  $sql='INSERT INTO pages ('.implode(',', $cols).') VALUES ('.implode(',', $vals).') RETURNING id';
  $st=$pdo->prepare($sql); $st->execute($par);

  header('Location: '.BASE_URL.'dashboard'); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>New page</title>
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css')) ?>">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;margin:0;background:#0b0b0c;color:#e5e7eb}
    .wrap{max-width:860px;margin:40px auto;padding:0 16px}
    h1{font-size:26px;margin:0 0 16px}
    form{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:20px}
    .row{margin-bottom:14px}
    label{display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px}
    input[type="text"],textarea{width:100%;box-sizing:border-box;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px}
    textarea{min-height:120px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
    .btn{display:inline-block;background:#34d399;color:#0b0b0c;border:none;border-radius:8px;padding:10px 14px;cursor:pointer;font-weight:700}
    .muted{color:#9ca3af;font-size:12px}
  </style>
</head>
<body>
<div class="wrap">
  <h1>New page</h1>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="grid">
      <div class="row"><label>Title</label><input type="text" name="title" required></div>
      <div class="row"><label>Artist</label><input type="text" name="artist" placeholder="Artist name (optional)"></div>
    </div>
    <div class="row">
      <label>Links (one per line, optionally “Label | URL”)</label>
      <textarea name="links_raw" placeholder="Spotify | https://open.spotify.com/track/..."></textarea>
      <div class="muted">Tip: add a label like <i>Spotify | https://...</i>. If no label, we’ll just save the URL.</div>
    </div>
    <input type="hidden" name="auto_image_url" id="auto_image_url">
    <div class="row"><label>Cover image (optional)</label><input type="file" name="cover" accept="image/*"></div>
    <div class="row"><label><input type="checkbox" name="published" value="1"> Published</label></div>
    <div class="row"><button type="submit" class="btn">Create page</button></div>
  </form>
</div>
</body>
</html>
