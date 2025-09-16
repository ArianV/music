<?php
require_auth();
csrf_check();
$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/**
 * --- Polyfills (in case your config.php doesn't have these helpers) ---
 * If you already added these functions in config.php, these definitions are
 * skipped automatically and your originals are used.
 */
if (!function_exists('http_get')) {
    function http_get(string $url, int $timeoutMs = 3000): ?string {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT_MS     => $timeoutMs,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'MusicPages/1.0'
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return $body ?: null;
        }
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => max(1, (int)ceil($timeoutMs/1000)),
                'header'  => "User-Agent: MusicPages/1.0\r\n",
            ],
            'ssl' => ['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? $body : null;
    }
}
if (!function_exists('parse_links_to_json')) {
    function parse_links_to_json(?string $raw): string {
        $raw = (string)$raw;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
        $out = [];
        foreach ($lines as $line) {
            $label = null; $url = $line;
            if (strpos($line, '|') !== false) {
                [$label, $url] = array_map('trim', explode('|', $line, 2));
            }
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $out[] = ['label' => $label ?: null, 'url' => $url];
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES);
    }
}
if (!function_exists('download_image_to_uploads')) {
    function download_image_to_uploads(string $url): ?string {
        if (!defined('UPLOAD_DIR') || !defined('UPLOAD_URI')) return null;
        $bin = http_get($url, 5000);
        if (!$bin) return null;
        $ext = '.jpg';
        $pathPart = parse_url($url, PHP_URL_PATH) ?? '';
        if (preg_match('/\.(png|webp|jpe?g)$/i', $pathPart, $m)) $ext = '.'.strtolower($m[1]);
        $name = 'cover_'.time().'_'.bin2hex(random_bytes(4)).$ext;
        $full = rtrim(UPLOAD_DIR, '/').'/'.$name;
        if (@file_put_contents($full, $bin) === false) return null;
        return rtrim(UPLOAD_URI, '/').'/'.$name;
    }
}

/** ---------- POST: create the page ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title        = trim($_POST['title']  ?? '');
    $artist       = trim($_POST['artist'] ?? '');
    $links_raw    = $_POST['links_raw']  ?? '';
    $auto_img_url = trim($_POST['auto_image_url'] ?? '');
    $cover_uri    = null;

    // 1) File upload (takes priority)
    if (!empty($_FILES['cover']['name'] ?? '')) {
        $safeName = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['cover']['name']);
        $target   = rtrim(UPLOAD_DIR, '/').'/'.$safeName;
        if (is_uploaded_file($_FILES['cover']['tmp_name'] ?? '') && @move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
            $cover_uri = rtrim(UPLOAD_URI, '/').'/'.$safeName;
        }
    }

    // 2) If no upload but we have auto-fetched image, save it
    if (!$cover_uri && $auto_img_url) {
        $saved = download_image_to_uploads($auto_img_url);
        if ($saved) $cover_uri = $saved;
    }

    // 3) Sanitize links -> JSON
    $links_json = parse_links_to_json($links_raw);

    // 4) Insert — keep columns minimal to match many schemas
    $sql = "INSERT INTO pages (user_id, title, artist, links_json, cover_uri, created_at, updated_at)
            VALUES (:uid, :title, :artist, :links, :cover, NOW(), NOW())
            RETURNING id";
    $stmt = db()->prepare($sql);
    $stmt->bindValue(':uid',    (int)$user['id'], PDO::PARAM_INT);
    $stmt->bindValue(':title',  $title);
    $stmt->bindValue(':artist', $artist);
    $stmt->bindValue(':links',  $links_json);
    $stmt->bindValue(':cover',  $cover_uri);
    $stmt->execute();
    $page_id = (int)$stmt->fetchColumn();

    // 5) Redirect to edit screen
    header('Location: ' . BASE_URL . 'pages/'.$page_id.'/edit');
    exit;
}

/** ---------- GET: render form ---------- */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Create new page</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
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
    .preview{margin-top:10px}
    img.cover{max-width:240px;border-radius:8px;border:1px solid #253041}
  </style>
</head>
<body>
<div class="wrap">
  <h1>New song page</h1>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="grid">
      <div class="row">
        <label>Title</label>
        <input type="text" name="title" placeholder="Song title">
      </div>
      <div class="row">
        <label>Artist</label>
        <input type="text" name="artist" placeholder="Artist name">
      </div>
    </div>

    <div class="row">
      <label>Links (one per line, optionally “Label | URL”)</label>
      <textarea id="links_raw" name="links_raw" placeholder="https://open.spotify.com/track/...
Apple Music | https://music.apple.com/album/...
https://youtu.be/ABC123"></textarea>
      <div class="muted">Tip: you can add a label like <i>Spotify | https://...</i>. If no label is provided, we’ll just save the URL.</div>
    </div>

    <input type="hidden" name="auto_image_url" id="auto_image_url">

    <div class="row">
      <label>Cover image (optional)</label>
      <input type="file" name="cover" accept="image/*">
    </div>

    <div class="row">
      <button type="submit" class="btn">Create page</button>
    </div>
  </form>
</div>

<script>
// If you already wired an /api/resolve endpoint + "Auto-fill" button,
// you can paste that JS here and it will store the fetched cover into #auto_image_url.
</script>
</body>
</html>
