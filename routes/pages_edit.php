<?php
// routes/pages_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/* ---------- helpers (polyfills if not in config) ---------- */
if (!function_exists('slugify')) {
  function slugify(string $s, int $maxLen = 80): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
    if (function_exists('iconv')) {
      $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
      if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    $s = trim($s, '-');
    $s = preg_replace('/-+/', '-', $s);
    if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
    return $s !== '' ? $s : 'page';
  }
}
if (!function_exists('unique_page_slug')) {
  function unique_page_slug(PDO $pdo, int $userId, string $title, string $artist = '', ?int $excludeId = null): string {
    $base = slugify(trim(($artist ? "$artist " : '') . $title), 72);
    $sql = "SELECT LOWER(slug) AS s FROM pages WHERE user_id=:uid AND slug ILIKE :like";
    $params = [':uid'=>$userId, ':like'=>$base.'%'];
    if ($excludeId) { $sql .= " AND id <> :id"; $params[':id'] = $excludeId; }
    $st = $pdo->prepare($sql); $st->execute($params);
    $taken = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 's'));
    if (!isset($taken[strtolower($base)])) return $base;
    for ($i=2; $i<=200; $i++) { $try = $base.'-'.$i; if (!isset($taken[strtolower($try)])) return $try; }
    return $base.'-'.bin2hex(random_bytes(2));
  }
}
if (!function_exists('parse_links_to_json')) {
  function parse_links_to_json(?string $raw): string {
    $raw = (string)$raw;
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
    $out = [];
    foreach ($lines as $line) {
      $label = null; $url = $line;
      if (strpos($line, '|') !== false) { [$label, $url] = array_map('trim', explode('|', $line, 2)); }
      if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
      $out[] = ['label'=>$label ?: null, 'url'=>$url];
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES);
  }
}
if (!function_exists('download_image_to_uploads')) {
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
}

/* ---------- resolve page key (id or slug) ---------- */
$page_key = $GLOBALS['page_id'] ?? $GLOBALS['page_key'] ?? null; // router can pass either name
if (!$page_key) {
  // try to parse from URL as last resort
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  if (preg_match('#^/pages/([^/]+)/edit$#', $path, $m)) $page_key = rawurldecode($m[1]);
}
if (!$page_key) { http_response_code(404); exit('Page not found'); }

/* ---------- load page (belongs to current user) ---------- */
$pdo = db();
if (ctype_digit((string)$page_key)) {
  $st = $pdo->prepare("SELECT * FROM pages WHERE id=:id AND user_id=:uid");
  $st->execute([':id'=>(int)$page_key, ':uid'=>$user['id']]);
} else {
  $st = $pdo->prepare("SELECT * FROM pages WHERE slug=:slug AND user_id=:uid");
  $st->execute([':slug'=>$page_key, ':uid'=>$user['id']]);
}
$page = $st->fetch();
if (!$page) { http_response_code(404); exit('Page not found'); }

/* ---------- current values ---------- */
$artist_val = $page['artist_name'] ?? $page['artist'] ?? '';
$cover_val  = $page['cover_uri'] ?? $page['cover_url'] ?? $page['cover_image'] ?? null;

/* ---------- POST: update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title        = trim($_POST['title']  ?? '');
  $artistInput  = trim($_POST['artist'] ?? '');
  $links_raw    = $_POST['links_raw']  ?? '';
  $auto_img_url = trim($_POST['auto_image_url'] ?? '');
  $published    = isset($_POST['published']) ? 1 : 0;

  if ($title === '') { http_response_code(422); exit('Title is required'); }

  $cover_uri    = $cover_val;
  // file upload
  if (!empty($_FILES['cover']['name'] ?? '')) {
    $safeName = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['cover']['name']);
    $target   = rtrim(UPLOAD_DIR, '/').'/'.$safeName;
    if (is_uploaded_file($_FILES['cover']['tmp_name'] ?? '') && @move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
      $cover_uri = rtrim(UPLOAD_URI, '/').'/'.$safeName;
    }
  }
  // auto image fallback
  if (!$cover_uri && $auto_img_url) {
    $saved = download_image_to_uploads($auto_img_url);
    if ($saved) $cover_uri = $saved;
  }

  $links_json    = parse_links_to_json($links_raw);
  $artist_to_use = $artistInput !== '' ? $artistInput : 'Unknown Artist';

  // Build dynamic UPDATE based on existing columns
  $sets   = [];
  $params = [':uid'=>$user['id']];

  // update by id; if we loaded via slug, we still have $page['id']
  $params[':id'] = (int)$page['id'];

  $sets[] = "title=:title";           $params[':title'] = $title;
  $sets[] = "links_json=:links_json"; $params[':links_json']=$links_json;
  $sets[] = "published=:pub";         $params[':pub'] = $published;

  if (table_has_column($pdo, 'pages', 'artist_name')) { $sets[]="artist_name=:artist_name"; $params[':artist_name']=$artist_to_use; }
  if (table_has_column($pdo, 'pages', 'artist'))      { $sets[]="artist=:artist";          $params[':artist']=$artist_to_use; }

  if ($cover_uri) {
    foreach (['cover_uri','cover_url','cover_image'] as $c) {
      if (table_has_column($pdo, 'pages', $c)) { $sets[]="$c=:cover"; $params[':cover']=$cover_uri; break; }
    }
  }

  // Only set slug if it's currently empty (keeps URLs stable)
  if (table_has_column($pdo, 'pages', 'slug') && empty($page['slug'])) {
    $params[':slug'] = unique_page_slug($pdo, (int)$user['id'], $title, $artist_to_use, (int)$page['id']);
    $sets[] = "slug=:slug";
  }

  if (table_has_column($pdo, 'pages', 'updated_at')) {
    $sets[] = "updated_at=NOW()";
  }

  $sql = "UPDATE pages SET ".implode(', ', $sets)." WHERE id=:id AND user_id=:uid";
  $upd = $pdo->prepare($sql);
  $upd->execute($params);

  header('Location: '.BASE_URL.'dashboard');
  exit;
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit page</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
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
    .btn{display:inline-block;background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}
    .muted{color:#9ca3af;font-size:12px}
    img.cover{max-width:180px;border-radius:8px;border:1px solid #253041;display:block;margin-top:8px}
    .inline{display:flex;align-items:center;gap:8px}
  </style>
</head>
<body>
<div class="wrap">
  <h1>Edit page</h1>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="grid">
      <div class="row">
        <label>Title</label>
        <input type="text" name="title" value="<?= e($page['title'] ?? '') ?>" required>
      </div>
      <div class="row">
        <label>Artist</label>
        <input type="text" name="artist" value="<?= e($artist_val) ?>" placeholder="Artist name (optional)">
      </div>
    </div>

    <div class="row">
      <label>Links (one per line, optionally “Label | URL”)</label>
      <textarea name="links_raw"><?php
        $links = $page['links_json'] ?? '[]';
        $arr = json_decode($links, true) ?: [];
        foreach ($arr as $it) {
            $label = trim((string)($it['label'] ?? ''));
            $url   = trim((string)($it['url'] ?? ''));
            echo e($label ? ($label.' | '.$url) : $url), "\n";
        }
      ?></textarea>
      <div class="muted">Tip: add a label like <i>Spotify | https://...</i>. If no label, we’ll just save the URL.</div>
    </div>

    <input type="hidden" name="auto_image_url" id="auto_image_url">

    <div class="row">
      <label>Cover image (optional)</label>
      <?php if ($cover_val): ?>
        <img class="cover" src="<?= e($cover_val) ?>" alt="cover">
      <?php endif; ?>
      <input type="file" name="cover" accept="image/*">
    </div>

    <div class="row inline">
      <input id="published" type="checkbox" name="published" value="1" <?= !empty($page['published']) ? 'checked' : '' ?>>
      <label for="published" style="margin:0">Published</label>
    </div>

    <div class="row">
      <button type="submit" class="btn">Save changes</button>
    </div>
  </form>
</div>
</body>
</html>
