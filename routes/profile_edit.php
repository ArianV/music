<?php
// routes/profile_edit.php
require_once __DIR__ . '/../config.php';

require_auth();
csrf_check();

$pdo = db();
$user = current_user();
if (!$user) { header('Location: ' . asset('login')); exit; }

// ---------- helpers ----------
if (!function_exists('slugify')) {
  function slugify(string $s): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if (function_exists('iconv')) { $t=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if($t!==false){ $s=$t; } }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-') ?: 'user';
  }
}
// normalize POST → bool or null
function bool_from_post($v): ?bool {
  if ($v === null || $v === '') return null;
  $v = strtolower((string)$v);
  if (in_array($v, ['1','true','t','yes','on','public'], true))  return true;
  if (in_array($v, ['0','false','f','no','off','private'], true)) return false;
  return null;
}
// small upload helper (returns [url|null, error|null])
function pe_upload_avatar_file(string $field='avatar'): array {
  if (empty($_FILES[$field]['name'] ?? '')) return [null, null];
  $err = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
  if ($err !== UPLOAD_ERR_OK) return [null, 'Upload error code '.$err];

  $tmp  = $_FILES[$field]['tmp_name'];
  $name = $_FILES[$field]['name'];
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['jpg','jpeg','png','webp','gif','jfif'])) {
    $f = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $f ? @finfo_file($f, $tmp) : null;
    if ($f) @finfo_close($f);
    $map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
    $ext = $map[$mime] ?? 'jpg';
  }
  $safe = 'avatar_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
  $dest = rtrim(UPLOAD_DIR,'/').'/'.$safe;

  if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);
  if (!is_writable(UPLOAD_DIR)) @chmod(UPLOAD_DIR, 0777);

  if (!is_uploaded_file($tmp) || !@move_uploaded_file($tmp, $dest)) {
    return [null, 'Server could not save the file (permissions/path)'];
  }
  return [rtrim(UPLOAD_URI,'/').'/'.$safe, null];
}
function socials_to_json(array $in): string {
  $out = [];
  foreach ($in as $k=>$v) {
    $v = trim((string)$v);
    if ($v !== '') $out[$k] = $v;
  }
  return json_encode($out, JSON_UNESCAPED_SLASHES);
}

// reload fresh user row
$st = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$st->execute([':id'=>$user['id']]);
$user = $st->fetch(PDO::FETCH_ASSOC) ?: $user;

$notice = null; $error = null; $upload_err = null;

// ---------- handle POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $display = trim($_POST['display_name'] ?? '');
  $handle  = trim($_POST['handle'] ?? '');
  $bio     = trim($_POST['bio'] ?? '');

  // privacy (fixes your error — never send '' to Postgres)
  $profile_public_in = bool_from_post($_POST['profile_public'] ?? null);

  // socials (matches the fields on your form; add/remove keys as you like)
  $socials_json = socials_to_json([
    'website'   => $_POST['website']   ?? '',
    'instagram' => $_POST['instagram'] ?? '',
    'twitter'   => $_POST['twitter']   ?? '',
    'tiktok'    => $_POST['tiktok']    ?? '',
    'soundcloud'=> $_POST['soundcloud']?? '',
    'spotify'   => $_POST['spotify']   ?? '',
  ]);

  // avatar
  [$new_avatar, $upload_err] = pe_upload_avatar_file('avatar');
  $avatar_uri = $new_avatar ?: ($user['avatar_uri'] ?? null);

  // sanitize handle and ensure uniqueness if changed
  if ($handle === '') $handle = $user['handle'] ?? '';
  $handle = slugify($handle);
  if ($handle !== ($user['handle'] ?? '')) {
    $chk = $pdo->prepare('SELECT 1 FROM users WHERE lower(handle)=lower(:h) AND id<>:me LIMIT 1');
    $chk->execute([':h'=>$handle, ':me'=>$user['id']]);
    if ($chk->fetch()) {
      // add numeric suffix until free
      $base=$handle; $n=2;
      do {
        $try = $base.'-'.$n;
        $chk->execute([':h'=>$try, ':me'=>$user['id']]);
        if (!$chk->fetch()) { $handle=$try; break; }
        $n++;
      } while ($n < 400);
    }
  }

  // build dynamic UPDATE
  $sets = [];
  $binds = [];

  $sets[] = 'updated_at = NOW()';

  if (table_has_column($pdo,'users','display_name')) { $sets[]='display_name = :d'; $binds[]=[':d',$display,PDO::PARAM_STR]; }
  if (table_has_column($pdo,'users','handle'))       { $sets[]='handle = :h';       $binds[]=[':h',$handle,PDO::PARAM_STR]; }
  if (table_has_column($pdo,'users','bio'))          { $sets[]='bio = :b';          $binds[]=[':b',$bio,PDO::PARAM_STR]; }
  if ($avatar_uri && table_has_column($pdo,'users','avatar_uri')) { $sets[]='avatar_uri = :a'; $binds[]=[':a',$avatar_uri,PDO::PARAM_STR]; }
  if (table_has_column($pdo,'users','socials_json')) { $sets[]='socials_json = :sj'; $binds[]=[':sj',$socials_json,PDO::PARAM_STR]; }

  // IMPORTANT: only include if not null and bind as real boolean
  if ($profile_public_in !== null && table_has_column($pdo,'users','profile_public')) {
    $sets[] = 'profile_public = :pub';
    $binds[] = [':pub', $profile_public_in, PDO::PARAM_BOOL];
  }

  $sql = 'UPDATE users SET '.implode(', ',$sets).' WHERE id = :id';
  $stmt = $pdo->prepare($sql);
  foreach ($binds as [$k,$v,$t]) { $stmt->bindValue($k,$v,$t); }
  $stmt->bindValue(':id', (int)$user['id'], PDO::PARAM_INT);
  $stmt->execute();

  $notice = 'Profile saved';
  // refresh user for display
  $st = $pdo->prepare('SELECT * FROM users WHERE id = :id');
  $st->execute([':id'=>$user['id']]);
  $user = $st->fetch(PDO::FETCH_ASSOC) ?: $user;
}

// ---------- view ----------
$title = 'Edit profile';
$head = <<<CSS
<style>
.card{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:20px}
.row{margin-bottom:14px}
label{display:block;font-size:14px;color:#a1a1aa;margin-bottom:6px}
input[type="text"],input[type="email"],input[type="url"],textarea,select{width:100%;box-sizing:border-box;background:#0f1217;border:1px solid #263142;color:#e5e7eb;border-radius:8px;padding:10px}
textarea{min-height:120px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#2563eb;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}
.btn-secondary{background:#1f2937;color:#e5e7eb;border:1px solid #2a3344}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pfp{width:80px;height:80px;border-radius:999px;object-fit:cover;border:1px solid #263142;background:#0f1217}
.notice{margin:10px 0;padding:10px 12px;border-radius:8px}
.notice.ok{background:#052e1a;color:#a7f3d0;border:1px solid #064e3b}
.notice.err{background:#3a0d0d;color:#fecaca;border:1px solid #7f1d1d}
.muted{color:#9ca3af;font-size:12px}
.pill{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;
      border-radius:999px;font-size:12px;font-weight:600}
.pill-public{background:#052e1a;color:#a7f3d0;border:1px solid #064e3b}
.pill-private{background:#2a1533;color:#e9d5ff;border:1px solid #6b21a8}
</style>
CSS;

$avatar = $user['avatar_uri'] ?? asset('assets/avatar-default.svg');
$display = $user['display_name'] ?? '';
$handle  = $user['handle'] ?? '';
$bio     = $user['bio'] ?? '';
$public  = !empty($user['profile_public']);

$sj = json_decode($user['socials_json'] ?? '{}', true) ?: [];
$website   = $sj['website']   ?? '';
$instagram = $sj['instagram'] ?? '';
$twitter   = $sj['twitter']   ?? '';
$tiktok    = $sj['tiktok']    ?? '';
$soundcloud= $sj['soundcloud']?? '';
$spotify   = $sj['spotify']   ?? '';

ob_start(); ?>
<a class="btn btn-secondary" href="<?= e(asset('dashboard')) ?>">← Back</a>
<div style="height:10px"></div>

<div class="card" style="max-width:900px;margin:0 auto">
  <h1 style="margin-top:0">Edit profile</h1>

  <?php if ($notice): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($upload_err): ?><div class="notice err"><?= e($upload_err) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="grid">
      <div class="row">
        <label>Avatar</label>
        <img class="pfp" src="<?= e($avatar) ?>?v=<?= time() ?>" alt="">
        <div style="height:8px"></div>
        <input type="file" name="avatar" accept="image/*">
        <div class="muted">PNG/JPG/WEBP. We’ll resize on the client.</div>
      </div>
      <div class="row">
      <div style="height: 48px;"></div>
        <div style="margin:6px 0 14px">
          <span class="pill <?= $public ? 'pill-public' : 'pill-private' ?>">
            <?= $public ? 'Public' : 'Private' ?>
          </span>
        </div>
        <label>Profile visibility</label>
        <select name="profile_public">
          <option value="">— Keep current (<?= $public?'Public':'Private' ?>) —</option>
          <option value="1" <?= $public ? 'selected' : '' ?>>Public</option>
          <option value="0" <?= !$public ? 'selected' : '' ?>>Private</option>
        </select>
      </div>
    </div>

    <div class="grid">
      <div class="row">
        <label>Display name</label>
        <input type="text" name="display_name" value="<?= e($display) ?>" placeholder="Lil Foaf" required>
      </div>
      <div class="row">
        <label>Handle</label>
        <div style="display:flex;gap:8px;align-items:center">
          <span class="muted">@</span>
          <input type="text" name="handle" value="<?= e($handle) ?>" placeholder="ex. lilfoaf">
        </div>
        <div class="muted">Your public URL: <?= e(asset('u/'.($handle?:'me'))) ?></div>
      </div>
    </div>

    <div class="row">
      <label>Bio</label>
      <textarea name="bio" placeholder="Tell listeners who you are..."><?= e($bio) ?></textarea>
    </div>

    <div class="row"><hr style="border:0;border-top:1px solid #1f2430"></div>

    <div class="grid">
      <div class="row">
        <label>Website</label>
        <input type="url" name="website" value="<?= e($website) ?>" placeholder="https://yoursite.com">
      </div>
      <div class="row">
        <label>Instagram</label>
        <input type="url" name="instagram" value="<?= e($instagram) ?>" placeholder="https://instagram.com/you">
      </div>
      <div class="row">
        <label>Twitter / X</label>
        <input type="url" name="twitter" value="<?= e($twitter) ?>" placeholder="https://x.com/you">
      </div>
      <div class="row">
        <label>TikTok</label>
        <input type="url" name="tiktok" value="<?= e($tiktok) ?>" placeholder="https://tiktok.com/@you">
      </div>
      <div class="row">
        <label>SoundCloud</label>
        <input type="url" name="soundcloud" value="<?= e($soundcloud) ?>" placeholder="https://soundcloud.com/you">
      </div>
      <div class="row">
        <label>Spotify Artist</label>
        <input type="url" name="spotify" value="<?= e($spotify) ?>" placeholder="https://open.spotify.com/artist/...">
      </div>
    </div>

    <div class="row" style="margin-top:10px">
      <button type="submit" class="btn">Save profile</button>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
