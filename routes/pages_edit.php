<?php
// routes/pages_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/* ---------- local helpers (intentionally NOT guarded) ---------- */
function ml_slugify(string $s, int $maxLen = 80): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
  if (function_exists('iconv')) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false) $s = $t;
  }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = preg_replace('/-+/', '-', $s);
  $s = trim($s, '-');
  if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
  return $s !== '' ? $s : 'page';
}
function ml_unique_page_slug(PDO $pdo, int $userId, string $title, string $artist = '', ?int $excludeId = null): string {
  $base = ml_slugify(trim(($artist ? "$artist " : '') . $title), 72);
  $sql  = "SELECT LOWER(slug) AS s FROM pages WHERE user_id = :uid AND slug ILIKE :like";
  $par  = [':uid'=>$userId, ':like'=>$base.'%'];
  if ($excludeId) { $sql .= " AND id <> :id"; $par[':id'] = $excludeId; }
  $st = $pdo->prepare($sql); $st->execute($par);
  $taken = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 's'));
  if (!isset($taken[$base])) return $base;
  for ($i=2; $i<=200; $i++) { $try=$base.'-'.$i; if (!isset($taken[$try])) return $try; }
  return $base.'-'.bin2hex(random_bytes(2));
}

/* ---------- resolve page key (id or slug) ---------- */
$page_key = $GLOBALS['page_id'] ?? $GLOBALS['page_key'] ?? null;
if (!$page_key) {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  if (preg_match('#^/pages/([^/]+)/edit$#', $path, $m)) $page_key = rawurldecode($m[1]);
}
if (!$page_key) { http_response_code(404); exit('Page not found'); }

/* ---------- load page ---------- */
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

  $cover_uri = $cover_val;
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

  $links_json    = parse_links_to_json($links_raw);
  $artist        = $artistInput !== '' ? $artistInput : 'Unknown Artist';

  // Build UPDATE
  $sets=[]; $par=[':id'=>(int)$page['id'], ':uid'=>$user['id']];
  $sets[] = 'title=:title';           $par[':title'] = $title;
  $sets[] = 'links_json=:links_json'; $par[':links_json'] = $links_json;
  $sets[] = 'published=:pub';         $par[':pub'] = $published;

  if (table_has_column($pdo,'pages','artist_name')) { $sets[]='artist_name=:artist_name'; $par[':artist_name']=$artist; }
  if (table_has_column($pdo,'pages','artist'))      { $sets[]='artist=:artist';           $par[':artist']=$artist; }

  if ($cover_uri) {
    foreach (['cover_uri','cover_url','cover_image'] as $c) {
      if (table_has_column($pdo,'pages',$c)) { $sets[]="$c=:cover"; $par[':cover']=$cover_uri; break; }
    }
  }

  // Only assign a slug if the record doesn't have one yet (keeps URLs stable)
  if (table_has_column($pdo,'pages','slug') && empty($page['slug'])) {
    $par[':slug'] = ml_unique_page_slug($pdo, (int)$user['id'], $title, $artist, (int)$page['id']);
    $sets[] = 'slug=:slug';
  }

  if (table_has_column($pdo,'pages','updated_at')) $sets[]='updated_at=NOW()';

  $sql = 'UPDATE pages SET '.implode(', ', $sets).' WHERE id=:id AND user_id=:uid';
  $upd = $pdo->prepare($sql); $upd->execute($par);

  header('Location: '.BASE_URL.'dashboard'); exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Edit page</title>
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
  </style>
</head>
<body>
<div class="wrap">
  <h1>Edit page</h1>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="grid">
      <div class="row"><label>Title</label><input type="text" name="title" value="<?= e($page['title'] ?? '') ?>" required></div>
      <div class="row"><label>Artist</label><input type="text" name="artist" value="<?= e($artist_val) ?>" placeholder="Artist name (optional)"></div>
    </div>
    <div class="row">
      <label>Links (one per line, optionally “Label | URL”)</label>
      <textarea name="links_raw"><?php
        $arr = json_decode($page['links_json'] ?? '[]', true) ?: [];
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
      <?php if ($cover_val): ?><img class="cover" src="<?= e($cover_val) ?>" alt="cover"><?php endif; ?>
      <input type="file" name="cover" accept="image/*">
    </div>
    <div class="row"><label><input type="checkbox" name="published" value="1" <?= !empty($page['published']) ? 'checked' : '' ?>> Published</label></div>
    <div class="row"><button type="submit" class="btn">Save changes</button></div>
  </form>
</div>
</body>
</html>
