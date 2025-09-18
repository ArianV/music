<?php
// routes/pages_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/* ---------- local helpers (namespaced with ml_) ---------- */
if (!function_exists('ml_slugify')) {
  function ml_slugify(string $s, int $maxLen = 80): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
    if (function_exists('iconv')) {
      $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
      if ($t !== false) $s = $t;
    }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    $s = trim($s, '-');
    if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
    return $s !== '' ? $s : 'page';
  }
}
if (!function_exists('ml_unique_page_slug')) {
  function ml_unique_page_slug(PDO $pdo, int $userId, string $title, string $artist = '', ?int $excludeId = null): string {
    $base = ml_slugify(trim(($artist ? "$artist " : '') . $title), 72);
    $sql  = "SELECT LOWER(slug) AS s FROM pages WHERE user_id = :uid AND slug ILIKE :like";
    $par  = [':uid'=>$userId, ':like'=>$base.'%'];
    if ($excludeId) { $sql .= " AND id <> :id"; $par[':id'] = $excludeId; }
    $st = $pdo->prepare($sql); $st->execute($par);
    $taken = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 's'));
    if (!isset($taken[$base])) return $base;
    for ($i=2; $i<=200; $i++) { $try = $base.'-'.$i; if (!isset($taken[$try])) return $try; }
    return $base.'-'.bin2hex(random_bytes(2));
  }
}
if (!function_exists('ml_detect_service_label')) {
  function ml_detect_service_label(string $url): ?string {
    $u = strtolower($url);
    $h = parse_url($u, PHP_URL_HOST) ?: '';
    $hit = function(array $needles) use ($u,$h){ foreach ($needles as $n) if (str_contains($u,$n)||str_contains($h,$n)) return true; return false; };
    if ($hit(['open.spotify.com','spotify'])) return 'Spotify';
    if ($hit(['music.apple.com','itunes.apple.com','apple'])) return 'Apple Music';
    if ($hit(['soundcloud.com','soundcloud'])) return 'SoundCloud';
    if ($hit(['youtube.com','youtu.be','music.youtube'])) return 'YouTube';
    if ($hit(['music.amazon','amazon.com/music'])) return 'Amazon Music';
    if ($hit(['tidal.com','tidal'])) return 'TIDAL';
    if ($hit(['deezer.com','deezer'])) return 'Deezer';
    if ($hit(['bandcamp.com','bandcamp'])) return 'Bandcamp';
    if ($hit(['audiomack.com','audiomack'])) return 'Audiomack';
    return null;
  }
}
if (!function_exists('ml_links_array_to_json')) {
  function ml_links_array_to_json(array $urls): string {
    $out = [];
    foreach ($urls as $url) {
      $url = trim((string)$url);
      if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
      $out[] = ['label'=>ml_detect_service_label($url), 'url'=>$url];
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES);
  }
}
if (!function_exists('ml_download_image_to_uploads')) {
  function ml_download_image_to_uploads(string $url): ?string {
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

/* ---------- which page? (id or slug from router) ---------- */
$key = $GLOBALS['page_id'] ?? null;
if (!$key && isset($_GET['id'])) $key = $_GET['id'];
if (!$key) { http_response_code(404); exit('Page not found'); }

$pdo = db();
if (ctype_digit((string)$key)) {
  $st = $pdo->prepare("SELECT * FROM pages WHERE id=:id AND user_id=:uid");
  $st->execute([':id'=>(int)$key, ':uid'=>$user['id']]);
} else {
  $st = $pdo->prepare("SELECT * FROM pages WHERE slug=:slug AND user_id=:uid");
  $st->execute([':slug'=>$key, ':uid'=>$user['id']]);
}
$page = $st->fetch(PDO::FETCH_ASSOC);
if (!$page) { http_response_code(404); exit('Page not found'); }

$artist_val = $page['artist_name'] ?? $page['artist'] ?? '';
$cover_val  = $page['cover_uri'] ?? $page['cover_url'] ?? $page['cover_image'] ?? null;

/* ---------- POST: update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title        = trim($_POST['title']  ?? '');
  $artistInput  = trim($_POST['artist'] ?? '');
  $urls         = $_POST['links_url']   ?? [];
  $auto_img_url = trim($_POST['auto_image_url'] ?? '');
  $published    = isset($_POST['published']) ? 1 : 0;

  if ($title === '') { http_response_code(422); exit('Title is required'); }

  $artist     = $artistInput !== '' ? $artistInput : 'Unknown Artist';
  $links_json = ml_links_array_to_json((array)$urls);

  // Cover: keep unless replaced
  $cover_uri = $cover_val;
  if (!empty($_FILES['cover']['name'] ?? '')) {
    $safeName = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['cover']['name']);
    $target   = rtrim(UPLOAD_DIR, '/').'/'.$safeName;
    if (is_uploaded_file($_FILES['cover']['tmp_name'] ?? '') && @move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
      $cover_uri = rtrim(UPLOAD_URI, '/').'/'.$safeName;
    }
  }
  if (!$cover_uri && $auto_img_url) {
    $saved = ml_download_image_to_uploads($auto_img_url);
    if ($saved) $cover_uri = $saved;
  }

  // Dynamic UPDATE
  $sets = [];
  $par  = [':id'=>$page['id'], ':uid'=>$user['id']];

  $sets[] = 'title=:title';           $par[':title']      = $title;
  $sets[] = 'links_json=:links_json'; $par[':links_json'] = $links_json;
  if (table_has_column($pdo,'pages','published')) { $sets[]='published=:pub'; $par[':pub']=$published; }

  if (table_has_column($pdo,'pages','artist_name')) { $sets[]='artist_name=:artist'; $par[':artist']=$artist; }
  if (table_has_column($pdo,'pages','artist'))      { $sets[]='artist=:artist2';     $par[':artist2']=$artist; }

  if ($cover_uri) {
    foreach (['cover_uri','cover_url','cover_image'] as $c) {
      if (table_has_column($pdo, 'pages', $c)) { $sets[]="$c=:cover"; $par[':cover']=$cover_uri; break; }
    }
  }

  if (table_has_column($pdo,'pages','slug')) {
    $par[':slug'] = ml_unique_page_slug($pdo, (int)$user['id'], $title, $artist, (int)$page['id']);
    $sets[] = 'slug=:slug';
  }
  if (table_has_column($pdo,'pages','updated_at')) { $sets[]='updated_at=NOW()'; }

  $sql = "UPDATE pages SET ".implode(', ', $sets)." WHERE id=:id AND user_id=:uid";
  $pdo->prepare($sql)->execute($par);

  header('Location: '.BASE_URL.'dashboard'); exit;
}

/* ---------- prefill links for UI ---------- */
$prefill_urls = [];
if (!empty($page['links_json'])) {
  $arr = json_decode($page['links_json'], true) ?: [];
  foreach ($arr as $it) {
    $u = trim((string)($it['url'] ?? ''));
    if ($u) $prefill_urls[] = $u;
  }
}

/* ---------- view ---------- */
$title = 'Edit page';

// styles + JS for repeater + back pill
$head = <<<HTML
<style>
.titlebar{display:flex;align-items:center;gap:12px;margin:0 0 16px}
.backlink{
  display:inline-flex;align-items:center;gap:8px;
  padding:8px 12px;border:1px solid #2a3344;border-radius:10px;
  color:#a1a1aa;text-decoration:none;background:#0f1217;
}
.backlink:hover{color:#e5e7eb;border-color:#3b4254}
.backlink .icon{width:16px;height:16px;display:block}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:700px){.form-grid{grid-template-columns:1fr}}
img.cover{max-width:180px;border-radius:10px;border:1px solid #253041;display:block;margin-top:8px}

/* links repeater */
.links-wrap{margin-top:6px}
.link-row{display:flex;align-items:center;gap:10px;margin:8px 0}
.link-row .svc{min-width:108px;padding:6px 10px;border:1px solid #2a3344;border-radius:999px;background:#0f1217;color:#a1a1aa;text-align:center;font-size:12px}
.link-row input[type="url"]{flex:1;padding:10px;border:1px solid #222;border-radius:10px;background:#0f0f16;color:#e5e7eb}
.link-row .remove{background:#232938;border:1px solid #2a3344;color:#a1a1aa;padding:8px 10px;border-radius:10px}
.add-link{margin-top:8px;background:#1f2937;border:1px solid #2a3344;color:#e5e7eb;padding:10px 12px;border-radius:10px}
.small{font-size:.9rem;color:#a1a1aa}
.but-row{display: flex;align-items: center;gap: 10px;margin: 8px 0;}
</style>
<script>
(function(){
  const svcDetect = (url) => {
    const u = (url||'').toLowerCase();
    if (u.includes('spotify')) return 'Spotify';
    if (u.includes('music.apple') || u.includes('itunes.apple')) return 'Apple Music';
    if (u.includes('soundcloud')) return 'SoundCloud';
    if (u.includes('youtu.be') || u.includes('youtube')) return 'YouTube';
    if (u.includes('music.amazon') || u.includes('amazon.com/music')) return 'Amazon Music';
    if (u.includes('tidal')) return 'TIDAL';
    if (u.includes('deezer')) return 'Deezer';
    if (u.includes('bandcamp')) return 'Bandcamp';
    if (u.includes('audiomack')) return 'Audiomack';
    return 'Link';
  };

  function makeRow(initialUrl, placeholder){
    const row = document.createElement('div');
    row.className = 'link-row';
    const label = svcDetect(initialUrl||'');
    row.innerHTML = `
      <div class="svc">\${label}</div>
      <input type="url" name="links_url[]" value="\${initialUrl?initialUrl.replace(/"/g,'&quot;'):''}" placeholder="\${placeholder||'Paste link'}" inputmode="url" spellcheck="false">
      <button type="button" class="remove" aria-label="Remove">Remove</button>
    `;
    const input = row.querySelector('input');
    const svc   = row.querySelector('.svc');
    input.addEventListener('input', () => { svc.textContent = svcDetect(input.value); });
    row.querySelector('.remove').addEventListener('click', () => row.remove());
    return row;
  }

  window.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('links-list');
    const add  = document.getElementById('add-link');
    const pre  = JSON.parse(document.getElementById('prefill-links').textContent || '[]');
    if (pre.length) pre.forEach(u => list.appendChild(makeRow(u, 'Paste link')));
    if (!pre.length) list.appendChild(makeRow('', 'Paste Spotify link'));
    add.addEventListener('click', () => {
      list.appendChild(makeRow('', 'Paste link (Spotify, Apple Music, YouTube, etc.)'));
    });
  });
})();
</script>
HTML;

$slug_or_id = $page['slug'] ?: (string)$page['id'];
$publicUrl  = rtrim(BASE_URL,'/').'/s/'.rawurlencode($slug_or_id);

ob_start(); ?>
<div class="titlebar">
  <a href="#" class="backlink" aria-label="Go back"
     onclick="if(history.length>1){history.back();}else{location.href='<?= e(asset('dashboard')) ?>';}return false;">
    <svg class="icon" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 19.5 8 12l7.5-7.5 1.5 1.5L11 12l6 6-1.5 1.5z"/></svg>
    <span>Back</span>
  </a>
  <h1 style="margin:0">Edit page</h1>
  <div style="flex:1"></div>
  <button type="button" class="btn" onclick="navigator.clipboard?.writeText('<?= e($publicUrl) ?>')?.then(()=>this.textContent='Copied!')">Copy public link</button>
</div>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="form-grid">
      <div>
        <label class="small">Title</label>
        <input type="text" name="title" value="<?= e($page['title'] ?? '') ?>" required>
      </div>
      <div>
        <label class="small">Artist</label>
        <input type="text" name="artist" value="<?= e($artist_val) ?>" placeholder="Artist name (optional)">
      </div>
    </div>

    <div class="links-wrap">
      <label class="small" style="display:block;margin-bottom:6px">Links</label>
      <div id="links-list"></div>
        <div class="but-row">
          <button type="button" id="add-link" class="add-link">+ Add link</button>
          <div class="small" style="margin-top:6px">Just paste URLs — we’ll detect the service automatically.</div>
        </div>
    </div>

    <script type="application/json" id="prefill-links"><?= json_encode($prefill_urls, JSON_UNESCAPED_SLASHES) ?></script>

    <input type="hidden" name="auto_image_url" id="auto_image_url">

    <div class="form-row" style="padding-left:0;padding-right:0">
      <label class="small" style="display:block;margin-bottom:6px">Cover image (optional)</label>
      <?php if ($cover_val): ?>
        <img class="cover" src="<?= e($cover_val) ?>" alt="cover">
      <?php endif; ?>
      <input type="file" name="cover" accept="image/*">
    </div>

    <div class="form-row" style="align-items:center;padding-left:0">
      <label class="small" style="display:inline-flex;align-items:center;gap:8px">
        <input type="checkbox" name="published" value="1" <?= !empty($page['published']) ? 'checked' : '' ?>> Published
      </label>
    </div>

    <div class="form-row" style="padding-left:0">
      <button type="submit" class="btn btn-primary">Save changes</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
