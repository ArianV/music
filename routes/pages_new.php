<?php
// routes/pages_new.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/* ----------------- helpers ----------------- */
function ml_slugify(string $s, int $maxLen = 80): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
  if (function_exists('iconv')) { $t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if($t!==false) $s=$t; }
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
  $st = db()->prepare($sql); $st->execute($par);
  $taken = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 's'));
  if (!isset($taken[$base])) return $base;
  for ($i=2; $i<=200; $i++) { $try = $base.'-'.$i; if (!isset($taken[$try])) return $try; }
  return $base.'-'.bin2hex(random_bytes(2));
}
function detect_service_label(string $url): ?string {
  $u = strtolower($url);
  $h = parse_url($u, PHP_URL_HOST) ?: '';
  $match = function(array $needles) use ($u,$h){
    foreach ($needles as $n) if (str_contains($u,$n) || str_contains($h,$n)) return true;
    return false;
  };
  if ($match(['open.spotify.com','spotify'])) return 'Spotify';
  if ($match(['music.apple.com','itunes.apple.com','apple'])) return 'Apple Music';
  if ($match(['soundcloud.com','soundcloud'])) return 'SoundCloud';
  if ($match(['youtube.com','youtu.be','music.youtube'])) return 'YouTube';
  if ($match(['music.amazon','amazon.com/music'])) return 'Amazon Music';
  if ($match(['tidal.com','tidal'])) return 'TIDAL';
  if ($match(['deezer.com','deezer'])) return 'Deezer';
  if ($match(['bandcamp.com','bandcamp'])) return 'Bandcamp';
  if ($match(['audiomack.com','audiomack'])) return 'Audiomack';
  return null;
}
function links_array_to_json(array $urls): string {
  $out = [];
  foreach ($urls as $url) {
    $url = trim((string)$url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
    $label = detect_service_label($url);
    $out[] = ['label'=>$label, 'url'=>$url];
  }
  return json_encode($out, JSON_UNESCAPED_SLASHES);
}
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

/* ----------------- POST: create ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title        = trim($_POST['title']  ?? '');
  $artistInput  = trim($_POST['artist'] ?? '');
  $urls         = $_POST['links_url']   ?? [];
  $auto_img_url = trim($_POST['auto_image_url'] ?? '');
  $published    = isset($_POST['published']) ? 1 : 0;

  if ($title === '') { http_response_code(422); exit('Title is required'); }

  $pdo    = db();
  $artist = $artistInput !== '' ? $artistInput : 'Unknown Artist';

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
  $par = [':uid'=>$user['id'], ':title'=>$title, ':links_json'=>links_array_to_json((array)$urls), ':pub'=>$published];

  if (table_has_column($pdo,'pages','artist_name')) { $cols[]='artist_name'; $vals[]=':artist';  $par[':artist']=$artist; }
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

/* ----------------- view ----------------- */
$title = 'New page';
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

/* links repeater */
.links-wrap{margin-top:6px}
.link-row{display:flex;align-items:center;gap:10px;margin:8px 0}
.link-row .svc{min-width:108px;padding:6px 10px;border:1px solid #2a3344;border-radius:999px;background:#0f1217;color:#a1a1aa;text-align:center;font-size:12px}
.link-row input[type="url"]{flex:1;padding:10px;border:1px solid #222;border-radius:10px;background:#0f0f16;color:#e5e7eb}
.link-row .remove{background:#232938;border:1px solid #2a3344;color:#a1a1aa;padding:8px 10px;border-radius:10px}
.add-link{margin-top:8px;background:#1f2937;border:1px solid #2a3344;color:#e5e7eb;padding:10px 12px;border-radius:10px}
.small{font-size:.9rem;color:#a1a1aa}
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

  function makeRow(placeholder){
    const row = document.createElement('div');
    row.className = 'link-row';
    row.innerHTML = `
      <div class="svc">Spotify</div>
      <input type="url" name="links_url[]" placeholder="\${placeholder||'Paste link'}" inputmode="url" spellcheck="false">
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

    // if empty, seed one Spotify row
    if (!list.children.length) list.appendChild(makeRow('Paste Spotify link'));

    add.addEventListener('click', () => {
      list.appendChild(makeRow('Paste link (Spotify, Apple Music, YouTube, etc.)'));
    });
  });
})();
</script>
HTML;

ob_start(); ?>
<div class="titlebar">
  <a href="#" class="backlink" aria-label="Go back"
     onclick="if(history.length>1){history.back();}else{location.href='<?= e(asset('dashboard')) ?>';}return false;">
    <svg class="icon" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 19.5 8 12l7.5-7.5 1.5 1.5L11 12l6 6-1.5 1.5z"/></svg>
    <span>Back</span>
  </a>
  <h1 style="margin:0">New page</h1>
</div>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="form-grid">
      <div>
        <label class="small">Title</label>
        <input type="text" name="title" required>
      </div>
      <div>
        <label class="small">Artist</label>
        <input type="text" name="artist" placeholder="Artist name (optional)">
      </div>
    </div>

    <div class="links-wrap">
      <label class="small" style="display:block;margin-bottom:6px">Links</label>
      <div id="links-list"></div>
      <button type="button" id="add-link" class="add-link">+ Add link</button>
      <div class="small" style="margin-top:6px">Just paste URLs — we’ll detect the service automatically.</div>
    </div>

    <input type="hidden" name="auto_image_url" id="auto_image_url">

    <div class="form-row" style="padding-left:0;padding-right:0;margin-top:12px">
      <label class="small" style="display:block;margin-bottom:6px">Cover image (optional)</label>
      <input type="file" name="cover" accept="image/*">
    </div>

    <div class="form-row" style="align-items:center;padding-left:0">
      <label class="small" style="display:inline-flex;align-items:center;gap:8px">
        <input type="checkbox" name="published" value="1"> Published
      </label>
    </div>

    <div class="form-row" style="padding-left:0">
      <button type="submit" class="btn btn-primary">Create page</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
