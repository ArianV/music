<?php
// routes/pages_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

/* ---------- helpers (scoped to this file) ---------- */
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
  $st = $pdo->prepare($sql); $st->execute($par);
  $taken = array_flip(array_column($st->fetchAll(PDO::FETCH_ASSOC), 's'));
  if (!isset($taken[$base])) return $base;
  for ($i=2; $i<=200; $i++) { $try = $base.'-'.$i; if (!isset($taken[$try])) return $try; }
  return $base.'-'.bin2hex(random_bytes(2));
}
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
$page = $st->fetch();
if (!$page) { http_response_code(404); exit('Page not found'); }

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

  $artist     = $artistInput !== '' ? $artistInput : 'Unknown Artist';
  $links_json = parse_links_to_json($links_raw);

  // Cover handling (keep current unless replaced)
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

  // Build dynamic UPDATE
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
  $upd = $pdo->prepare($sql);
  $upd->execute($par);

  header('Location: '.BASE_URL.'dashboard'); exit;
}

/* ---------- view ---------- */
$title = 'Edit page';

// head styles (back button + grid)
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
</style>
HTML;

ob_start(); ?>
<div class="titlebar">
  <a href="#" class="backlink" aria-label="Go back"
     onclick="if(history.length>1){history.back();}else{location.href='<?= e(asset('dashboard')) ?>';}return false;">
    <svg class="icon" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 19.5 8 12l7.5-7.5 1.5 1.5L11 12l6 6-1.5 1.5z"/></svg>
    <span>Back</span>
  </a>
  <h1 style="margin:0">Edit page</h1>
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

    <div class="form-row" style="padding-left:0;padding-right:0">
      <label class="small" style="display:block;margin-bottom:6px">Links (one per line, optionally “Label | URL”)</label>
      <textarea name="links_raw"><?php
        $links = $page['links_json'] ?? '[]';
        $arr = json_decode($links, true) ?: [];
        foreach ($arr as $it) {
          $label = trim((string)($it['label'] ?? ''));
          $url   = trim((string)($it['url'] ?? ''));
          echo e($label ? ($label.' | '.$url) : $url), "\n";
        }
      ?></textarea>
      <div class="small" style="margin-top:6px;color:#a1a1aa">
        Tip: add a label like <i>Spotify | https://...</i>. If no label, we’ll just save the URL.
      </div>
    </div>

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
