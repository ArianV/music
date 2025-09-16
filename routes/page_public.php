<?php
// ====== page_public.php ======
require_once __DIR__ . '/../config.php';

// Resolve key from URL or ?id=
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$key  = null;
if (preg_match('#/pages/([^/]+)/?$#', $path, $m)) {
  $key = rawurldecode($m[1]);
} elseif (isset($_GET['id'])) {
  $key = $_GET['id'];
}
if ($key === null || $key === '') { http_response_code(404); exit('Not found'); }

$pdo  = db();
$page = null;

if (ctype_digit((string)$key)) {
  $st = $pdo->prepare("SELECT p.*, u.id AS owner_id FROM pages p LEFT JOIN users u ON u.id=p.user_id WHERE p.id = :id");
  $st->execute([':id' => (int)$key]);
  $page = $st->fetch();
} else {
  $st = $pdo->prepare("SELECT p.*, u.id AS owner_id FROM pages p LEFT JOIN users u ON u.id=p.user_id WHERE p.slug = :slug");
  $st->execute([':slug' => $key]);
  $page = $st->fetch();
  if (!$page) {
    $norm = slugify($key);
    if ($norm !== $key) {
      $st = $pdo->prepare("SELECT p.*, u.id AS owner_id FROM pages p LEFT JOIN users u ON u.id=p.user_id WHERE p.slug = :slug");
      $st->execute([':slug' => $norm]);
      $page = $st->fetch();
    }
  }
}
if (!$page) { http_response_code(404); exit('Not found'); }

$owner_id  = (int)($page['owner_id'] ?? 0);
$viewer_id = (int)(current_user()['id'] ?? 0);
$is_owner  = $owner_id && $owner_id === $viewer_id;
$is_public = (int)($page['published'] ?? 0) === 1;
if (!$is_public && !$is_owner) { http_response_code(404); exit('Not found'); }

$title   = trim((string)($page['title'] ?? 'Untitled'));
$artist  = trim((string)($page['artist_name'] ?? $page['artist'] ?? ''));
$cover   = page_cover($page);
$links   = json_decode($page['links_json'] ?? '[]', true) ?: [];

$meta_title = $title . ($artist ? " · $artist" : '');
$meta_desc  = $artist ? "$artist — $title" : $title;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($meta_title) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="<?= e(BASE_URL) ?>">
  <meta name="description" content="<?= e($meta_desc) ?>">
  <?php if ($cover): ?>
    <meta property="og:image" content="<?= e($cover) ?>">
    <meta name="twitter:image" content="<?= e($cover) ?>">
  <?php endif; ?>
  <meta property="og:title" content="<?= e($meta_title) ?>">
  <meta property="og:description" content="<?= e($meta_desc) ?>">
  <meta property="og:type" content="website">
  <style>
    :root {
      --bg: #0b0b0c;
      --panel: #191c22;
      --border: #232938;
      --muted: #a1a1aa;
      --text: #e5e7eb;
      --brand: #1DB954; /* Spotify */
      --primary: #2563eb;
    }
    *{box-sizing:border-box}
    html,body{height:100%}
    body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif}
    header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#111318;border-bottom:1px solid #1f2430;position:sticky;top:0;z-index:10}
    header .brand{font-weight:700;color:#64f0be}
    header nav a{color:#64f0be;text-decoration:none;margin-left:14px}

    main{max-width:960px;margin:40px auto;padding:0 16px;display:flex;justify-content:center}
    .card{width:280px;padding:16px;border-radius:16px;background:var(--panel);border:1px solid var(--border);box-shadow:0 6px 20px rgba(0,0,0,.25)}
    .card-media{width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#0f1217;margin-bottom:12px}
    .card .cover{width:100%;height:100%;object-fit:cover;display:block}
    .card .placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px}

    .title{font-weight:800;font-size:26px;margin:4px 0 0}
    .artist{color:var(--muted);font-size:13px;margin:6px 0 14px}

    .links{display:flex;flex-wrap:wrap;gap:10px}
    .btn-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;line-height:1;border:1px solid transparent;transition:filter .15s ease}
    .btn-pill .icon{width:16px;height:16px;fill:currentColor}
    .btn-spotify{background:var(--brand);color:#0b0b0c}
    .btn-spotify:hover{filter:brightness(1.05)}
    .btn-default{background:var(--primary);color:#fff}
    .btn-default:hover{filter:brightness(1.05)}

    footer{padding:14px 16px;color:#9ca3af;border-top:1px solid #1f2430;background:#111318}
  </style>
</head>
<body>
<header>
  <div class="brand">Music Landing</div>
  <nav>
    <?php if ($is_owner): ?>
      <a href="<?= e(BASE_URL) ?>dashboard">Dashboard</a>
      <a href="<?= e(BASE_URL) ?>pages/<?= e($page['slug'] ?? $page['id']) ?>/edit">Edit</a>
      <a href="<?= e(BASE_URL) ?>logout">Logout</a>
    <?php else: ?>
      <a href="<?= e(BASE_URL) ?>">Home</a>
    <?php endif; ?>
  </nav>
</header>

<main>
  <article class="card">
    <div class="card-media">
      <?php if ($cover): ?>
        <img class="cover" src="<?= e($cover) ?>" alt="<?= e($title) ?>">
      <?php else: ?>
        <div class="placeholder">No artwork</div>
      <?php endif; ?>
    </div>

    <h1 class="title"><?= e($title) ?></h1>
    <?php if ($artist): ?><p class="artist"><?= e($artist) ?></p><?php endif; ?>

    <?php if (!empty($links)): ?>
      <div class="links">
      <?php foreach ($links as $link):
        $label = trim((string)($link['label'] ?? 'Open'));
        $url   = trim((string)($link['url'] ?? ''));
        if (!$url) continue;
        $labell = strtolower($label);
        $isSpotify = str_contains($labell, 'spotify') || str_contains($url, 'open.spotify.com');
      ?>
        <a class="btn-pill <?= $isSpotify ? 'btn-spotify' : 'btn-default' ?>" href="<?= e($url) ?>" target="_blank" rel="noopener">
          <?php if ($isSpotify): ?>
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 0a12 12 0 1 0 .001 24.001A12 12 0 0 0 12 0Zm5.47 17.28a.9.9 0 0 1-1.24.31c-3.4-2.07-7.7-2.54-12.75-1.4a.9.9 0 1 1-.39-1.75c5.47-1.22 10.2-.69 14 1.6.43.26.57.82.27 1.24Zm1.66-3.13a1.12 1.12 0 0 1-1.54.39c-3.89-2.38-9.82-3.08-14.4-1.7a1.12 1.12 0 1 1-.64-2.15c5.2-1.56 11.65-.78 16.06 1.93.53.33.7 1.02.33 1.53Zm.15-3.27c-4.43-2.63-11.78-2.87-16.02-1.69a1.33 1.33 0 1 1-.73-2.57c4.88-1.39 12.96-1.1 18 1.86a1.33 1.33 0 0 1-1.25 2.4Z"/>
            </svg>
            <span>Open on Spotify</span>
          <?php else: ?>
            <span><?= e($label ?: 'Open') ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </article>
</main>

<footer>
  © <?= date('Y') ?> Music Landing
</footer>
</body>
</html>
