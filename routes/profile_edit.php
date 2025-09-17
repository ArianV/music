<?php
// routes/profile_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$pdo  = db();
$user = current_user();

/* ---------- helpers (scoped) ---------- */
function pe_handleify(string $s, int $maxLen=30): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  if (function_exists('iconv')) {
    $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    if ($t !== false) $s = $t;
  }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = preg_replace('/-+/', '-', $s);
  $s = trim($s, '-');
  if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
  return $s ?: 'user';
}
function pe_socials_to_json(array $in): string {
  $allowed = ['website','twitter','instagram','tiktok','youtube','soundcloud','bandcamp','spotify','apple'];
  $out = [];
  foreach ($allowed as $k) {
    $v = trim((string)($in[$k] ?? ''));
    if ($v !== '') $out[$k] = $v;
  }
  return json_encode($out, JSON_UNESCAPED_SLASHES);
}

/* ---------- load current row ---------- */
$st = $pdo->prepare("SELECT * FROM users WHERE id=:id");
$st->execute([':id'=>$user['id']]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

/* ---------- POST: save ---------- */
$error = null;
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $display = trim($_POST['display_name'] ?? '');
  $handle  = trim($_POST['handle'] ?? '');
  $bio     = trim($_POST['bio'] ?? '');
  $socials = $_POST['socials'] ?? [];

  // derive handle if blank; normalize if provided
  if ($handle === '') {
    $handle = pe_handleify($display ?: ($me['handle'] ?? 'user'));
  } else {
    $handle = pe_handleify($handle);
  }

  // enforce unique handle (case-insensitive) if the column exists
  if (table_has_column($pdo,'users','handle')) {
    $chk = $pdo->prepare("SELECT id FROM users WHERE lower(handle)=lower(:h) AND id<>:id LIMIT 1");
    $chk->execute([':h'=>$handle, ':id'=>$user['id']]);
    if ($chk->fetch()) $error = 'That handle is taken. Please choose another.';
  }

  // avatar upload (optional)
  $avatar_uri = $me['avatar_uri'] ?? null;
  if (!$error && !empty($_FILES['avatar']['name'] ?? '')) {
    $safe = time().'_'.preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['avatar']['name']);
    $tgt  = rtrim(UPLOAD_DIR,'/').'/'.$safe;
    if (is_uploaded_file($_FILES['avatar']['tmp_name'] ?? '') && @move_uploaded_file($_FILES['avatar']['tmp_name'], $tgt)) {
      $avatar_uri = rtrim(UPLOAD_URI,'/').'/'.$safe;
    }
  }

  if (!$error) {
    // Build a dynamic UPDATE that only touches columns that exist
    $sets = []; $par = [':id'=>$user['id']];

    if (table_has_column($pdo,'users','display_name')) { $sets[]='display_name=:d'; $par[':d']=$display ?: null; }
    if (table_has_column($pdo,'users','handle'))       { $sets[]='handle=:h';        $par[':h']=$handle; }
    if (table_has_column($pdo,'users','bio'))          { $sets[]='bio=:b';           $par[':b']=$bio ?: null; }
    if (table_has_column($pdo,'users','socials_json')) { $sets[]='socials_json=:s';  $par[':s']=pe_socials_to_json((array)$socials); }
    if (table_has_column($pdo,'users','avatar_uri') && $avatar_uri) { $sets[]='avatar_uri=:a'; $par[':a']=$avatar_uri; }
    if (table_has_column($pdo,'users','updated_at'))   { $sets[]='updated_at=NOW()'; }

    if ($sets) {
      $sql = "UPDATE users SET ".implode(', ',$sets)." WHERE id=:id";
      $pdo->prepare($sql)->execute($par);
      $saved = true;

      // refresh in-memory row for re-render
      $st = $pdo->prepare("SELECT * FROM users WHERE id=:id");
      $st->execute([':id'=>$user['id']]);
      $me = $st->fetch(PDO::FETCH_ASSOC) ?: $me;
    }
  }
}

/* ---------- view data ---------- */
$display = $me['display_name'] ?? '';
$handle  = $me['handle'] ?? '';
$bio     = $me['bio'] ?? '';
$avatar  = $me['avatar_uri'] ?? null;
$socials = json_decode($me['socials_json'] ?? '[]', true) ?: [];

$publicUrl = rtrim(BASE_URL,'/').'/u/'.rawurlencode($handle ?: 'your-handle');

/* ---------- head (minimal styles) ---------- */
$title = 'Edit profile';
$head = <<<CSS
<style>
.titlebar{display:flex;align-items:center;gap:12px;margin:0 0 16px}
.backlink{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #2a3344;border-radius:10px;color:#a1a1aa;text-decoration:none;background:#0f1217}
.backlink:hover{color:#e5e7eb;border-color:#3b4254}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:760px){.form-grid{grid-template-columns:1fr}}
.row{margin:12px 0}
.small{font-size:.9rem;color:#a1a1aa}
.avatar{width:96px;height:96px;border-radius:50%;object-fit:cover;border:1px solid #263142;background:#0f1217}
.urlbox{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.urlbox code{background:#0f1217;border:1px solid #263142;padding:8px 10px;border-radius:8px}
.notice{border:1px solid #2a3344;background:#0f1217;padding:10px 12px;border-radius:10px;margin-bottom:12px}
.notice.ok{border-color:#14532d;background:#0c1a12;color:#86efac}
.notice.err{border-color:#7f1d1d;background:#1b1212;color:#fca5a5}
</style>
CSS;

/* ---------- render ---------- */
ob_start(); ?>
<div class="titlebar">
  <a href="<?= e(rtrim(BASE_URL,'/').'/dashboard') ?>" class="backlink">← Back</a>
  <h1 style="margin:0">Edit profile</h1>
</div>

<?php if ($saved): ?>
  <div class="notice ok">Profile saved.</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="notice err"><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="row" style="display:flex;gap:16px;align-items:center">
      <img class="avatar" src="<?= e($avatar ?: 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect width=%2264%22 height=%2264%22 rx=%2212%22 fill=%22%230B0B0C%22/></svg>') ?>" alt="">
      <div>
        <label class="small">Avatar</label>
        <input type="file" name="avatar" accept="image">
      </div>
    </div>

    <div class="form-grid">
      <div>
        <label class="small">Display name</label>
        <input type="text" name="display_name" value="<?= e($display) ?>" placeholder="Lil Foaf">
      </div>
      <div>
        <label class="small">Handle</label>
        <div style="display:flex;gap:8px;align-items:center">
          <div style="padding:10px 12px;background:#0f1217;border:1px solid #263142;border-radius:8px;color:#9ca3af">@</div>
          <input type="text" name="handle" value="<?= e($handle) ?>" placeholder="lil-foaf" pattern="[a-z0-9-]{2,30}" title="lowercase letters, numbers, dashes">
        </div>
        <div class="small" style="margin-top:6px">Your public URL: <code><?= e($publicUrl) ?></code></div>
      </div>
    </div>

    <div class="row">
      <label class="small">Bio</label>
      <textarea name="bio" rows="4" placeholder="Short intro..."><?= e($bio) ?></textarea>
    </div>

    <div class="row">
      <label class="small">Links</label>
      <div class="form-grid">
        <div><input type="url" name="socials[website]"    value="<?= e($socials['website']    ?? '') ?>" placeholder="Website URL"></div>
        <div><input type="url" name="socials[twitter]"    value="<?= e($socials['twitter']    ?? '') ?>" placeholder="Twitter / X URL"></div>
        <div><input type="url" name="socials[instagram]"  value="<?= e($socials['instagram']  ?? '') ?>" placeholder="Instagram URL"></div>
        <div><input type="url" name="socials[tiktok]"     value="<?= e($socials['tiktok']     ?? '') ?>" placeholder="TikTok URL"></div>
        <div><input type="url" name="socials[youtube]"    value="<?= e($socials['youtube']    ?? '') ?>" placeholder="YouTube URL"></div>
        <div><input type="url" name="socials[soundcloud]" value="<?= e($socials['soundcloud'] ?? '') ?>" placeholder="SoundCloud URL"></div>
        <div><input type="url" name="socials[bandcamp]"   value="<?= e($socials['bandcamp']   ?? '') ?>" placeholder="Bandcamp URL"></div>
        <div><input type="url" name="socials[spotify]"    value="<?= e($socials['spotify']    ?? '') ?>" placeholder="Spotify Artist URL"></div>
        <div><input type="url" name="socials[apple]"      value="<?= e($socials['apple']      ?? '') ?>" placeholder="Apple Music Artist URL"></div>
      </div>
    </div>

    <div class="row urlbox">
      <button type="button" class="btn" onclick="navigator.clipboard?.writeText('<?= e($publicUrl) ?>')?.then(()=>this.textContent='Copied!')">Copy profile URL</button>
    </div>

    <div class="row">
      <button type="submit" class="btn btn-primary">Save profile</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
