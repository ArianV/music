<?php
// routes/profile_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$pdo  = db();
$user = current_user();

/* ---------- helpers ---------- */
function pe_handleify(string $s, int $maxLen=30): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  if (function_exists('iconv')) { $t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if($t!==false) $s=$t; }
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

/* ---------- load me ---------- */
$st = $pdo->prepare("SELECT * FROM users WHERE id=:id");
$st->execute([':id'=>$user['id']]);
$me = $st->fetch(PDO::FETCH_ASSOC) ?: [];

/* ---------- save ---------- */
$error = null; $saved=false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $display   = trim($_POST['display_name'] ?? '');
  $handle    = trim($_POST['handle'] ?? '');
  $bio       = trim($_POST['bio'] ?? '');
  $socials   = (array)($_POST['socials'] ?? []);
  $visSel    = ($_POST['profile_visibility'] ?? 'private') === 'public';
  $isPublic  = $visSel ? true : false;

  $handle = $handle === '' ? pe_handleify($display ?: ($me['handle'] ?? 'user')) : pe_handleify($handle);

  // unique handle if column exists
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
    $sets=[]; $par=[':id'=>$user['id']];
    if (table_has_column($pdo,'users','display_name'))  { $sets[]='display_name=:d';     $par[':d']=$display ?: null; }
    if (table_has_column($pdo,'users','handle'))        { $sets[]='handle=:h';           $par[':h']=$handle; }
    if (table_has_column($pdo,'users','bio'))           { $sets[]='bio=:b';              $par[':b']=$bio ?: null; }
    if (table_has_column($pdo,'users','socials_json'))  { $sets[]='socials_json=:s';     $par[':s']=pe_socials_to_json($socials); }
    if (table_has_column($pdo,'users','avatar_uri') && $avatar_uri) { $sets[]='avatar_uri=:a'; $par[':a']=$avatar_uri; }
    if (table_has_column($pdo,'users','profile_public')){ $sets[]='profile_public=:pp';  $par[':pp']=$isPublic; } // boolean bind
    if (table_has_column($pdo,'users','updated_at'))    { $sets[]='updated_at=NOW()'; }

    if ($sets) {
      $sql="UPDATE users SET ".implode(', ',$sets)." WHERE id=:id";
      $pdo->prepare($sql)->execute($par);
      $saved=true;
      // reload row for fresh render
      $st = $pdo->prepare("SELECT * FROM users WHERE id=:id"); $st->execute([':id'=>$user['id']]); $me=$st->fetch(PDO::FETCH_ASSOC) ?: $me;
    }
  }
}

/* ---------- view data ---------- */
$display = $me['display_name'] ?? '';
$handle  = $me['handle'] ?? '';
$bio     = $me['bio'] ?? '';
$avatar  = $me['avatar_uri'] ?? null;
$public  = (bool)($me['profile_public'] ?? false);
$socials = json_decode($me['socials_json'] ?? '[]', true) ?: [];

$publicUrl = rtrim(BASE_URL,'/').'/u/'.rawurlencode($handle ?: 'your-handle');

/* ---------- head styles ---------- */
$title = 'Edit profile';
$head = <<<CSS
<style>
.titlebar{display:flex;align-items:center;gap:12px;margin:0 0 16px}
.backlink{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid #2a3344;border-radius:10px;color:#a1a1aa;text-decoration:none;background:#0f1217}
.backlink:hover{color:#e5e7eb;border-color:#3b4254}
.card .section-title{font-size:13px;color:#a1a1aa;margin:10px 0 6px}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
@media (max-width:760px){.form-grid{grid-template-columns:1fr}}
.row{margin:12px 0}
.small{font-size:.9rem;color:#a1a1aa}
.avatar{width:84px;height:84px;border-radius:50%;object-fit:cover;border:1px solid #263142;background:#0f1217}
.notice{border:1px solid #2a3344;background:#0f1217;padding:10px 12px;border-radius:10px;margin-bottom:12px}
.notice.ok{border-color:#14532d;background:#0c1a12;color:#86efac}
.notice.err{border-color:#7f1d1d;background:#1b1212;color:#fca5a5}
.icon-input{position:relative}
.icon-input .ico{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;opacity:.8}
.icon-input input{padding-left:36px}
.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #2a3344;border-radius:999px;background:#0f1217;color:#a1a1aa;font-size:12px}
.select{appearance:none;background:#0f1217;border:1px solid #263142;border-radius:10px;color:#e5e7eb;padding:10px 12px;width:100%}
.select-wrap{position:relative}
.select-wrap:after{content:"▾";position:absolute;right:12px;top:50%;transform:translateY(-50%);color:#a1a1aa;font-size:12px;pointer-events:none}
.badge{display:inline-block;background:#151325;border:1px solid #3f3cbb;color:#c7d2fe;border-radius:999px;padding:4px 8px;font-size:12px}
</style>
CSS;

/* ---------- render ---------- */
ob_start(); ?>
<div class="titlebar">
  <a href="<?= e(rtrim(BASE_URL,'/').'/dashboard') ?>" class="backlink">← Back</a>
  <h1 style="margin:0">Edit profile</h1>
</div>

<?php if ($saved): ?><div class="notice ok">Profile saved.</div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="card">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <!-- Avatar -->
    <div class="row" style="display:flex;gap:16px;align-items:center">
      <img class="avatar" src="<?= e($avatar ?: 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect width=%2264%22 height=%2264%22 rx=%2212%22 fill=%22%230B0B0C%22/></svg>') ?>" alt="">
      <div>
        <div class="small">Avatar</div>
        <input type="file" name="avatar" accept="image/*">
      </div>
    </div>

    <!-- Name / Handle / Privacy -->
    <div class="form-grid">
      <div>
        <div class="small">Display name</div>
        <input type="text" name="display_name" value="<?= e($display) ?>" placeholder="Lil Foaf">
      </div>
      <div>
        <div class="small">Handle</div>
        <div style="display:flex;gap:8px;align-items:center">
          <div class="pill">@</div>
          <input type="text" name="handle" value="<?= e($handle) ?>" placeholder="lil-foaf" pattern="[a-z0-9-]{2,30}" title="lowercase letters, numbers, dashes">
        </div>
      </div>
      <div>
        <div class="small">Privacy</div>
        <div class="select-wrap">
          <select class="select" name="profile_visibility">
            <option value="private" <?= !$public ? 'selected' : '' ?>>Private</option>
            <option value="public"  <?=  $public ? 'selected' : '' ?>>Public</option>
          </select>
        </div>
        <div class="small" style="margin-top:6px">
          Status:
          <?php if ($public): ?>
            <span class="badge">Public</span>
          <?php else: ?>
            <span class="badge" style="border-color:#9a3412;background:#1b130d;color:#fdba74">Private</span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Bio -->
    <div class="row">
      <div class="small">Bio</div>
      <textarea name="bio" rows="4" placeholder="Short intro..."><?= e($bio) ?></textarea>
    </div>

    <!-- Socials -->
    <div class="row">
      <div class="section-title">Links</div>
      <div class="form-grid">
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M3 12a5 5 0 0 1 5-5h3v2H8a3 3 0 0 0 0 6h3v2H8a5 5 0 0 1-5-5Zm8-3h3a5 5 0 0 1 0 10h-3v-2h3a3 3 0 0 0 0-6h-3V9Z"/></svg>
          <input type="url" name="socials[website]" value="<?= e($socials['website'] ?? '') ?>" placeholder="Website URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M22 4.01c-.8.36-1.67.6-2.58.71a4.49 4.49 0 0 0-7.66 4.1A12.77 12.77 0 0 1 3.15 5.15a4.48 4.48 0 0 0 1.39 6 4.46 4.46 0 0 1-2.03-.56v.06a4.5 4.5 0 0 0 3.6 4.41 4.5 4.5 0 0 1-2.02.08 4.5 4.5 0 0 0 4.2 3.12A9 9 0 0 1 2 19.54 12.73 12.73 0 0 0 8.9 21.5c8.3 0 12.85-6.87 12.85-12.82 0-.2 0-.4-.02-.6A9.1 9.1 0 0 0 22 4.01Z"/></svg>
          <input type="url" name="socials[twitter]" value="<?= e($socials['twitter'] ?? '') ?>" placeholder="Twitter / X URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2c3.2 0 3.6.01 4.85.07 1.17.06 1.96.24 2.57.5.62.24 1.14.57 1.66 1.09.52.52.85 1.04 1.09 1.66.26.61.44 1.4.5 2.57.06 1.25.07 1.66.07 4.85s-.01 3.6-.07 4.85c-.06 1.17-.24 1.96-.5 2.57a4.6 4.6 0 0 1-1.09 1.66 4.6 4.6 0 0 1-1.66 1.09c-.61.26-1.4.44-2.57.5-1.25.06-1.66.07-4.85.07s-3.6-.01-4.85-.07c-1.17-.06-1.96-.24-2.57-.5a4.6 4.6 0 0 1-1.66-1.09 4.6 4.6 0 0 1-1.09-1.66c-.26-.61-.44-1.4-.5-2.57C2.01 15.6 2 15.2 2 12s.01-3.6.07-4.85c.06-1.17.24-1.96.5-2.57.24-.62.57-1.14 1.09-1.66.52-.52 1.04-.85 1.66-1.09.61-.26 1.4-.44 2.57-.5C8.4 2.01 8.8 2 12 2Zm0 3.3A6.7 6.7 0 1 0 18.7 12 6.7 6.7 0 0 0 12 5.3Zm0 11A4.3 4.3 0 1 1 16.3 12 4.3 4.3 0 0 1 12 16.3Z"/></svg>
          <input type="url" name="socials[instagram]" value="<?= e($socials['instagram'] ?? '') ?>" placeholder="Instagram URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2 2 7l10 5 10-5-10-5Zm0 8L2 5v12l10 5 10-5V5l-10 5Z"/></svg>
          <input type="url" name="socials[tiktok]" value="<?= e($socials['tiktok'] ?? '') ?>" placeholder="TikTok URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M10 16.5v-9l6 4.5-6 4.5Z"/></svg>
          <input type="url" name="socials[youtube]" value="<?= e($socials['youtube'] ?? '') ?>" placeholder="YouTube URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M18 7H6v10h12V7Zm-1.5 8.5h-9v-7h9v7Z"/></svg>
          <input type="url" name="socials[soundcloud]" value="<?= e($socials['soundcloud'] ?? '') ?>" placeholder="SoundCloud URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M18 18H6a3 3 0 0 1-3-3V9a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3Z"/></svg>
          <input type="url" name="socials[bandcamp]" value="<?= e($socials['bandcamp'] ?? '') ?>" placeholder="Bandcamp URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="#1DB954"/><path fill="#0b0b0c" d="M17.5 10.8c-2.6-1.5-6.2-1.9-10.1-.9-.4.1-.7-.1-.8-.5s.1-.7.5-.8c4.2-1 8.2-.5 11.1 1.1.3.2.5.6.3 1-.2.3-.6.5-1 .3Zm0 3c-2.6-1.5-6.5-2-10-.9-.3.1-.7-.1-.8-.5-.1-.3.1-.7.5-.8 3.9-1 8.1-.6 11.1 1.1.3.2.5.6.3 1-.2.3-.6.5-1 .3Zm-1.1 2.7c-2.4-1.4-5.4-1.6-8.9-.8-.3.1-.7-.1-.8-.4-.1-.3.1-.7.4-.8 3.8-.9 7.1-.7 9.7.8.3.2.4.6.2.9-.2.3-.6.4-.9.3Z"/></svg>
          <input type="url" name="socials[spotify]" value="<?= e($socials['spotify'] ?? '') ?>" placeholder="Spotify Artist URL">
        </div>
        <div class="icon-input">
          <svg class="ico" viewBox="0 0 24 24"><path fill="currentColor" d="M16 2H8a6 6 0 0 0-6 6v8a6 6 0 0 0 6 6h8a6 6 0 0 0 6-6V8a6 6 0 0 0-6-6Z"/></svg>
          <input type="url" name="socials[apple]" value="<?= e($socials['apple'] ?? '') ?>" placeholder="Apple Music Artist URL">
        </div>
      </div>
    </div>

    <!-- Actions -->
    <div class="row" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <button type="submit" class="btn btn-primary">Save profile</button>
      <button type="button" class="btn" onclick="navigator.clipboard?.writeText('<?= e($publicUrl) ?>')?.then(()=>this.textContent='Copied!')">
        Copy profile URL
      </button>
      <?php if (!$public): ?>
        <span class="small">This profile is private. Switch to <b>Public</b> to let fans visit your page.</span>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
