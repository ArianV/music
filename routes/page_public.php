<?php
// routes/page_public.php
require_once __DIR__ . '/../config.php';

/* ---------- helpers ---------- */
if (!function_exists('find_user_by_handle')) {
  function find_user_by_handle(PDO $pdo, string $handle): ?array {
    foreach (['handle','username','slug','name'] as $col) {
      if (!table_has_column($pdo, 'users', $col)) continue;
      $st = $pdo->prepare("SELECT * FROM users WHERE lower($col)=lower(:h) LIMIT 1");
      $st->execute([':h'=>$handle]);
      if ($u = $st->fetch(PDO::FETCH_ASSOC)) return $u;
    }
    return null;
  }
}

if (!function_exists('service_meta')) {
  function service_meta(string $label, string $url): array {
    $lab = strtolower($label); $u = strtolower($url);
    $has = fn($n) => str_contains($lab,$n) || str_contains($u,$n);

    // All SVGs have fixed width/height so they can’t blow up
    $svg = [
      'spotify'    => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M12 0a12 12 0 1 0 .001 24.001A12 12 0 0 0 12 0Zm5.47 17.28a.9.9 0 0 1-1.24.31c-3.4-2.07-7.7-2.54-12.75-1.4a.9.9 0 1 1-.39-1.75c5.47-1.22 10.2-.69 14 1.6.43.26.57.82.27 1.24Zm1.66-3.13a1.12 1.12 0 0 1-1.54.39c-3.89-2.38-9.82-3.08-14.4-1.7a1.12 1.12 0 1 1-.64-2.15c5.2-1.56 11.65-.78 16.06 1.93.53.33.7 1.02.33 1.53Zm.15-3.27c-4.43-2.63-11.78-2.87-16.02-1.69a1.33 1.33 0 1 1-.73-2.57c4.88-1.39 12.96-1.1 18 1.86a1.33 1.33 0 0 1-1.25 2.4Z"/></svg>',
      'apple'      => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M9 3v14a3 3 0 1 1-2-2.83V5.5a.5.5 0 0 1 .37-.48l8-2a.5.5 0 0 1 .63.48V16a3 3 0 1 1-2-2.83V5.27L9 6.77V3z"/></svg>',
      'soundcloud' => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M17.5 10a4.5 4.5 0 0 0-4.35-3.5c-2.1 0-3.85 1.5-4.22 3.5H8a3.5 3.5 0 1 0 0 7h9.5A3.5 3.5 0 1 0 17.5 10z"/></svg>',
      'amazon'     => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M5 18.5a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 5 18.5Zm1.5-3.5a5 5 0 1 1 7.9-5.92.5.5 0 0 0 .6.29 3.5 3.5 0 1 1 .25 6.83H6.5Z"/></svg>',
      'youtube'    => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M23 12c0-2.1-.2-3.9-.6-4.7-.3-.7-.9-1.3-1.6-1.6C19.9 5.1 12 5.1 12 5.1s-7.9 0-8.8.6C2.5 6 1.9 6.6 1.6 7.3 1.2 8.1 1 9.9 1 12s.2 3.9.6 4.7c.3.7.9 1.3 1.6 1.6.9.6 8.8.6 8.8.6s7.9 0 8.8-.6c.7-.3 1.3-.9 1.6-1.6.4-.8.6-2.6.6-4.7ZM10 15.5v-7l6 3.5-6 3.5Z"/></svg>',
      'tidal'      => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M6 7 9 10 6 13 3 10 6 7Zm12 0 3 3-3 3-3-3 3-3Zm-6 0 3 3-3 3-3-3 3-3Zm0 6 3 3-3 3-3-3 3-3Z"/></svg>',
      'deezer'     => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M3 16h3v3H3v-3Zm4-2h3v5H7v-5Zm4-3h3v8h-3V11Zm4-3h3v11h-3V8Z"/></svg>',
      'bandcamp'   => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M4 7h8l-4 10H0L4 7Zm10 0h10v10H14V7Z"/></svg>',
      'audiomack'  => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M4 12h2l1 5 2-10 2 10 1-5h2l-2 7h-4L4 12Z"/></svg>',
    ];

    $map = [
      'spotify'    => ['m'=>['spotify','open.spotify.com'],           't'=>'Open on Spotify'],
      'apple'      => ['m'=>['apple music','music.apple.com','itunes'],'t'=>'Open on Apple Music'],
      'soundcloud' => ['m'=>['soundcloud','soundcloud.com'],          't'=>'Open on SoundCloud'],
      'amazon'     => ['m'=>['amazon music','music.amazon','/music/'],'t'=>'Open on Amazon Music'],
      'youtube'    => ['m'=>['youtube','youtu.be','music.youtube'],   't'=>'Open on YouTube'],
      'tidal'      => ['m'=>['tidal','tidal.com'],                    't'=>'Open on TIDAL'],
      'deezer'     => ['m'=>['deezer','deezer.com'],                  't'=>'Open on Deezer'],
      'bandcamp'   => ['m'=>['bandcamp','bandcamp.com'],              't'=>'Open on Bandcamp'],
      'audiomack'  => ['m'=>['audiomack','audiomack.com'],            't'=>'Open on Audiomack'],
    ];

    foreach ($map as $k=>$c) foreach ($c['m'] as $needle) if ($has($needle)) {
      return ['key'=>$k,'class'=>'btn-svc-'.$k,'text'=>$c['t'],'icon'=>$svg[$k] ?? ''];
    }
    return ['key'=>null,'class'=>'btn-default','text'=>($label ?: 'Open'),'icon'=>''];
  }
}

/* ---------- resolve URL ---------- */
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '/';
$handle = $GLOBALS['handle']   ?? null;
$key    = $GLOBALS['page_key'] ?? null;

if (!$handle || !$key) {
  if (preg_match('#^/@([^/]+)/([^/]+)/?$#', $path, $m)) {
    $handle = rawurldecode($m[1]); $key = rawurldecode($m[2]);
  } elseif (preg_match('#^/pages/([^/]+)/?$#', $path, $m)) {
    $key = rawurldecode($m[1]);
  } elseif (isset($_GET['id'])) {
    $key = (string)$_GET['id'];
  }
}
if (!$key && !$handle) { http_response_code(404); exit('Not found'); }

/* ---------- load page ---------- */
$pdo = db();
$page = null;

if ($handle) {
  $owner = find_user_by_handle($pdo, $handle);
  if (!$owner) { http_response_code(404); exit('Not found'); }

  if (ctype_digit((string)$key)) {
    $st = $pdo->prepare("SELECT p.*, u.id AS owner_id
                         FROM pages p JOIN users u ON u.id=p.user_id
                         WHERE p.id=:id AND p.user_id=:uid");
    $st->execute([':id'=>(int)$key, ':uid'=>(int)$owner['id']]);
    $page = $st->fetch();
  } else {
    $st = $pdo->prepare("SELECT p.*, u.id AS owner_id
                         FROM pages p JOIN users u ON u.id=p.user_id
                         WHERE p.slug=:slug AND p.user_id=:uid");
    $st->execute([':slug'=>$key, ':uid'=>(int)$owner['id']]);
    $page = $st->fetch();
    if (!$page) {
      $norm = slugify($key);
      if ($norm !== $key) {
        $st = $pdo->prepare("SELECT p.*, u.id AS owner_id
                             FROM pages p JOIN users u ON u.id=p.user_id
                             WHERE p.slug=:slug AND p.user_id=:uid");
        $st->execute([':slug'=>$norm, ':uid'=>(int)$owner['id']]);
        $page = $st->fetch();
      }
    }
  }
} else {
  if (ctype_digit((string)$key)) {
    $st = $pdo->prepare("SELECT p.*, u.id AS owner_id
                         FROM pages p LEFT JOIN users u ON u.id=p.user_id
                         WHERE p.id=:id");
    $st->execute([':id'=>(int)$key]);
    $page = $st->fetch();
  } else {
    $st = $pdo->prepare("SELECT p.*, u.id AS owner_id
                         FROM pages p LEFT JOIN users u ON u.id=p.user_id
                         WHERE p.slug=:slug");
    $st->execute([':slug'=>$key]);
    $page = $st->fetch();
    if (!$page) {
      $norm = slugify($key);
      if ($norm !== $key) {
        $st = $pdo->prepare("SELECT p.*, u.id AS owner_id
                             FROM pages p LEFT JOIN users u ON u.id=p.user_id
                             WHERE p.slug=:slug");
        $st->execute([':slug'=>$norm]);
        $page = $st->fetch();
      }
    }
  }
}
if (!$page) { http_response_code(404); exit('Not found'); }

/* ---------- visibility ---------- */
$owner_id  = (int)($page['owner_id'] ?? $page['user_id'] ?? 0);
$viewer_id = (int)(current_user()['id'] ?? 0);
$is_owner  = $owner_id && $owner_id === $viewer_id;
$is_public = (int)($page['published'] ?? 0) === 1;
if (!$is_public && !$is_owner) { http_response_code(404); exit('Not found'); }

/* ---------- render ---------- */
$title  = trim((string)($page['title'] ?? 'Untitled'));
$artist = trim((string)($page['artist_name'] ?? $page['artist'] ?? ''));
$cover  = page_cover($page);
$links  = json_decode($page['links_json'] ?? '[]', true) ?: [];

$meta_title = $title . ($artist ? " · $artist" : '');
$meta_desc  = $artist ? "$artist — $title" : $title;

ob_start(); ?>
<article class="card" style="max-width:340px;margin:24px auto;">
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
        $meta  = service_meta($label, $url);
      ?>
        <a class="btn-pill <?= e($meta['class']) ?>" href="<?= e($url) ?>" target="_blank" rel="noopener">
          <?= $meta['icon'] ?><span><?= e($meta['text']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</article>
<?php
$content = ob_get_clean();

/* Inject tiny CSS so pills render correctly even if main CSS lacks them */
$head  = '<meta name="description" content="'.e($meta_desc).'">' . "\n";
if ($cover) {
  $head .= '<meta property="og:image" content="'.e($cover).'">' . "\n";
  $head .= '<meta name="twitter:image" content="'.e($cover).'">' . "\n";
}
$head .= '<meta property="og:title" content="'.e($meta_title).'">' . "\n";
$head .= '<meta property="og:description" content="'.e($meta_desc).'">' . "\n";
$head .= '<meta property="og:type" content="website">' . "\n";

/* Minimal styles (safe to remove later if you’ve added these to assets/styles.css) */
$head .= <<<CSS
<style>
.btn-pill{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;line-height:1;border:1px solid transparent;transition:filter .15s ease}
.btn-pill .icon{width:16px;height:16px;fill:currentColor}
.btn-pill:hover{filter:brightness(1.05)}
.btn-default{background:#2563eb;color:#fff}
.btn-svc-spotify{background:#1DB954;color:#0b0b0c}
.btn-svc-apple{background:#fa2d48;color:#fff}
.btn-svc-soundcloud{background:#ff5500;color:#fff}
.btn-svc-amazon{background:#3b29ff;color:#fff}
.btn-svc-youtube{background:#ff0000;color:#fff}
.btn-svc-tidal{background:#0b0b0c;color:#e5e7eb;border:1px solid #2a3344}
.btn-svc-deezer{background:#00a0f0;color:#fff}
.btn-svc-bandcamp{background:#1e9d8b;color:#0b0b0c}
.btn-svc-audiomack{background:#ffb300;color:#0b0b0c}
.card-media{width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#0f1217;margin-bottom:12px}
.cover{width:100%;height:100%;object-fit:cover;display:block}
.title{font-weight:800;font-size:26px;margin:4px 0 0}
.artist{color:#a1a1aa;font-size:13px;margin:6px 0 14px}
.links{display:flex;flex-wrap:wrap;gap:10px;justify-content: center;padding-top: 10px;}
</style>
CSS;

$title = $meta_title;
require __DIR__ . '/../views/layout.php';
