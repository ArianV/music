<?php
// expects $page_id from index.php's regex route
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

if (!isset($page_id) || !is_int($page_id) || $page_id <= 0) {
    http_response_code(404); exit('Page not found');
}

// util: cache schema columns
if (!function_exists('table_has_column')) {
    function table_has_column(PDO $pdo, string $table, string $col): bool {
        static $cache = [];
        $key = strtolower($table);
        if (!isset($cache[$key])) {
            $st = db()->prepare("
                SELECT lower(column_name) AS c
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = :t
            ");
            $st->execute([':t' => $table]);
            $cache[$key] = array_column($st->fetchAll(), 'c');
        }
        return in_array(strtolower($col), $cache[$key], true);
    }
}

// 1) Load page that belongs to current user
$st = db()->prepare("SELECT * FROM pages WHERE id=:id AND user_id=:uid");
$st->execute([':id'=>$page_id, ':uid'=>$user['id']]);
$page = $st->fetch();
if (!$page) { http_response_code(404); exit('Page not found'); }

// helpers to read artist/cover regardless of column names
$artist_val = $page['artist_name'] ?? $page['artist'] ?? '';
$cover_val  = $page['cover_uri'] ?? $page['cover_url'] ?? $page['cover_image'] ?? null;

// 2) Handle POST (update)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title']  ?? '');
    $artistInput  = trim($_POST['artist'] ?? '');
    $links_raw    = $_POST['links_raw']  ?? '';
    $auto_img_url = trim($_POST['auto_image_url'] ?? '');
    $cover_uri    = $cover_val; // start with current

    // optional helpers (polyfills if not present)
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

    // file upload first
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
    $pdo   = db();
    $sets  = [];
    $params= [':id'=>$page_id, ':uid'=>$user['id']];

    $sets[] = "title=:title";           $params[':title'] = $title;
    $sets[] = "links_json=:links_json"; $params[':links_json']=$links_json;

    if (table_has_column($pdo, 'pages', 'artist_name')) { $sets[]="artist_name=:artist_name"; $params[':artist_name']=$artist_to_use; }
    if (table_has_column($pdo, 'pages', 'artist'))      { $sets[]="artist=:artist";          $params[':artist']=$artist_to_use; }

    if ($cover_uri) {
        foreach (['cover_uri','cover_url','cover_image'] as $c) {
            if (table_has_column($pdo, 'pages', $c)) { $sets[]="$c=:cover"; $params[':cover']=$cover_uri; break; }
        }
    }

    if (table_has_column($pdo, 'pages', 'slug') && function_exists('slugify')) {
        $sets[] = "slug=:slug";
        $params[':slug'] = slugify(($artist_to_use ? $artist_to_use.' ' : '').$title);
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
  <base href="<?= e(BASE_URL) ?>"><!-- fixes relative asset paths -->
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
      <div class="row">
        <label>Title</label>
        <input type="text" name="title" value="<?= e($page['title'] ?? '') ?>" required>
      </div>
      <div class="row">
        <label>Artist</label>
        <input type="text" name="artist" value="<?= e($artist_val) ?>" required>
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

    <div class="row">
      <button type="submit" class="btn">Save changes</button>
    </div>
  </form>
</div>
</body>
</html>
