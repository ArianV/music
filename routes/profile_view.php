<?php
// routes/profile_view.php
require_once __DIR__ . '/../config.php';

$pdo = db();
$me  = current_user();

$handle = $GLOBALS['handle'] ?? '';
if ($handle === '') { http_response_code(404); exit('Not found'); }

// load profile owner by handle (case-insensitive)
$st = $pdo->prepare("SELECT * FROM users WHERE lower(handle) = lower(:h) LIMIT 1");
$st->execute([':h' => $handle]);
$owner = $st->fetch(PDO::FETCH_ASSOC);
if (!$owner) { http_response_code(404); exit('User not found'); }

$isOwner  = $me && ((int)$me['id'] === (int)$owner['id']);
$isPublic = (bool)($owner['profile_public'] ?? false);

if (!$isPublic && !$isOwner) {
  $title = 'Profile is private';
  ob_start(); ?>
  <div class="card" style="max-width:640px;margin:40px auto;padding:22px;border:1px solid #202636;border-radius:14px;background:#0f1217">
    <h1 style="margin-top:0">This profile is private</h1>
    <p class="muted">The owner has not made their profile public.</p>
  </div>
  <?php
  $content = ob_get_clean();
  require __DIR__ . '/../views/layout.php';
  exit;
}

// fetch owner’s pages
$q = "SELECT id, title, slug, cover_uri, links_json, updated_at
      FROM pages
      WHERE user_id = :uid";
$params = [':uid' => (int)$owner['id']];
if (!$isOwner) { $q .= " AND published = TRUE"; }
$q .= " ORDER BY updated_at DESC NULLS LAST, id DESC";
$st = $pdo->prepare($q);
$st->execute($params);
$pages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ----- helpers for socials -----
function svg_icon(string $name): string {
  // minimalist inline icons (currentColor)
  switch ($name) {
    case 'globe': return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20a10 10 0 0 0 0-20m-1 2.06A8.01 8.01 0 0 0 4.06 11H11V4.06m2 0V11h6.94A8.01 8.01 0 0 0 13 4.06M4.06 13A8.01 8.01 0 0 0 11 19.94V13H4.06m8.94 0v6.94A8.01 8.01 0 0 0 19.94 13H13z"/></svg>';
    case 'instagram': return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5m0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3zm5 3.5A5.5 5.5 0 1 1 6.5 13A5.5 5.5 0 0 1 12 7.5m0 2A3.5 3.5 0 1 0 15.5 13A3.5 3.5 0 0 0 12 9.5M17.75 6a1.25 1.25 0 1 1-1.25 1.25A1.25 1.25 0 0 1 17.75 6"/></svg>';
    case 'x': return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h4.6l5.27 7.53L16.5 3H21l-7.85 10.77L21 21h-4.6l-5.38-7.69L7.5 21H3l8.11-10.83z"/></svg>';
    case 'tiktok': return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M13 3h3.1a6.5 6.5 0 0 0 4 3.5v3.1A9.6 9.6 0 0 1 15 8.5V15a6 6 0 1 1-6-6a6.1 6.1 0 0 1 2 .36V12a3.5 3.5 0 1 0 0 7z"/></svg>';
    case 'soundcloud': return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M11 7a5 5 0 0 1 5 5h2a3 3 0 1 1 0 6H7a4 4 0 1 1 1.8-7.6A5 5 0 0 1 11 7"/></svg>';
    case 'spotify': return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M12 1.8A10.2 10.2 0 1 0 22.2 12A10.2 10.2 0 0 0 12 1.8m4.6 14.93a.9.9 0 0 1-1.23.3a11.3 11.3 0 0 0-9.2-.88a.9.9 0 1 1-.6-1.71a13.1 13.1 0 0 1 10.7 1.03a.9.9 0 0 1 .33 1.26m1.65-3.13a1 1 0 0 1-1.37.34a14.6 14.6 0 0 0-11.7-1.13a1 1 0 1 1-.62-1.91c4.7-1.52 9.9-.86 13.3 1.27a1 1 0 0 1 .39 1.43m.13-3.25a1.15 1.15 0 0 1-1.57.4a17.7 17.7 0 0 0-14.2-1.2a1.15 1.15 0 0 1-.68-2.2a20 20 0 0 1 16 1.37a1.15 1.15 0 0 1 .45 1.63"/></svg>';
    default: return '<svg class="sicon" viewBox="0 0 24 24"><path fill="currentColor" d="M10.59 13.41L9.17 12l6-6H11V4h9v9h-2V7.83zM19 19H5V5h5V3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-5h-2z"/></svg>';
  }
}
function normalize_social(string $key, string $val): ?string {
  $v = trim($val);
  if ($v === '') return null;
  if (preg_match('~^https?://~i', $v)) return $v;
  $v = ltrim($v, '@');
  switch ($key) {
    case 'instagram':  return 'https://instagram.com/'.$v;
    case 'twitter':    return 'https://x.com/'.$v;
    case 'tiktok':     return 'https://tiktok.com/@'.$v;
    case 'soundcloud': return (strpos($v,'.')===false ? 'https://soundcloud.com/'.$v : 'https://'.$v);
    case 'spotify':
      if (preg_match('~^spotify:artist:(.+)$~',$v,$m)) return 'https://open.spotify.com/artist/'.$m[1];
      return (strpos($v,'open.spotify.com')!==false ? ('https://'.ltrim($v,'https://')) : 'https://open.spotify.com/artist/'.$v);
    case 'website':    return preg_match('~^https?://~i',$v) ? $v : 'https://'.$v;
    default:           return preg_match('~^https?://~i',$v) ? $v : 'https://'.$v;
  }
}

// decode socials and build list
$sj = json_decode($owner['socials_json'] ?? '{}', true) ?: [];
$map = [
  'website'   => ['label'=>'Website','icon'=>'globe'],
  'instagram' => ['label'=>'Instagram','icon'=>'instagram'],
  'twitter'   => ['label'=>'Twitter/X','icon'=>'x'],
  'tiktok'    => ['label'=>'TikTok','icon'=>'tiktok'],
  'soundcloud'=> ['label'=>'SoundCloud','icon'=>'soundcloud'],
  'spotify'   => ['label'=>'Spotify','icon'=>'spotify'],
];
$socials = [];
foreach ($map as $key=>$meta) {
  $u = normalize_social($key, (string)($sj[$key] ?? ''));
  if ($u) $socials[] = ['key'=>$key, 'url'=>$u, 'label'=>$meta['label'], 'icon'=>$meta['icon']];
}

$avatar  = $owner['avatar_uri'] ?? asset('assets/avatar-default.svg');
$display = $owner['display_name'] ?? ($owner['handle'] ?? 'Artist');
$title   = $display.' • Profile';

$head = <<<CSS
<style>
.profile{max-width:1120px;margin:24px auto}
.header{display:flex;gap:16px;align-items:center;margin-bottom:14px}
.pfp{width:72px;height:72px;border-radius:999px;object-fit:cover;border:1px solid #263142;background:#0f1217}
.mono{color:#a1a1aa;font-size:12px}
.btn{display:inline-flex;align-items:center;gap:8px;background:#1f2937;color:#e5e7eb;border:1px solid #2a3344;border-radius:10px;padding:8px 12px;text-decoration:none}
.btn:hover{background:#232e44}

/* info card with bio + socials */
.info{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:14px;margin:8px 0 18px}
.bio{margin:0 0 10px;white-space:pre-line;color:#d1d5db}
.socials{display:flex;flex-wrap:wrap;gap:10px}
.sbtn{display:inline-flex;align-items:center;gap:8px;padding:8px 10px;border-radius:999px;border:1px solid #253041;background:#0f1217;color:#e5e7eb;text-decoration:none}
.sbtn:hover{background:#151a25}
.sicon{width:18px;height:18px}

/* subtle provider accents */
.s-spotify{border-color:#22c55e;color:#a7f3d0}
.s-instagram{border-color:#d946ef}
.s-twitter{border-color:#60a5fa}
.s-tiktok{border-color:#f472b6}
.s-soundcloud{border-color:#fb923c}
.s-website{border-color:#94a3b8}

/* grid of pages */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px}
.card-page{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:12px}
.card-page img{width:100%;height:160px;object-fit:cover;border-radius:8px;border:1px solid #253041;background:#0f1217}
.pill{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:600}
.pill-public{background:#052e1a;color:#a7f3d0;border:1px solid #064e3b}
.pill-private{background:#2a1533;color:#e9d5ff;border:1px solid #6b21a8}
@media (max-width:600px){
  .header{align-items:flex-start}
}
</style>
CSS;

ob_start(); ?>
<div class="profile">
  <div class="header">
    <img class="pfp" src="<?= e($avatar) ?>" alt="">
    <div>
      <h1 style="margin:0 0 4px"><?= e($display) ?></h1>
      <div class="mono">@<?= e($owner['handle'] ?? '') ?></div>
    </div>
    <div style="flex:1"></div>
    <?php if ($isOwner): ?>
      <span class="pill <?= $isPublic ? 'pill-public':'pill-private' ?>" style="margin-right:10px">
        <?= $isPublic ? 'Public' : 'Private' ?>
      </span>
      <a class="btn" href="<?= e(asset('profile')) ?>">Edit profile</a>
    <?php endif; ?>
  </div>

  <?php if (!empty($owner['bio']) || $socials): ?>
    <div class="info">
      <?php if (!empty($owner['bio'])): ?>
        <p class="bio"><?= nl2br(e($owner['bio'])) ?></p>
      <?php endif; ?>
      <?php if ($socials): ?>
        <div class="socials">
          <?php foreach ($socials as $s): ?>
            <a class="sbtn s-<?= e($s['key']) ?>" href="<?= e($s['url']) ?>" target="_blank" rel="noopener noreferrer">
              <?= svg_icon($s['icon']) ?><span><?= e($s['label']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($pages): ?>
    <div class="grid">
      <?php foreach ($pages as $p): $cover = $p['cover_uri'] ?? null; ?>
        <a class="card-page" href="<?= e(asset('s/' . rawurlencode($p['slug'] ?: $p['id']))) ?>">
          <?php if ($cover): ?><img src="<?= e($cover) ?>" alt=""><?php endif; ?>
          <div style="margin-top:8px">
            <div style="font-weight:600"><?= e($p['title'] ?? 'Untitled') ?></div>
            <div class="mono"><?= e(date('M j, Y', strtotime($p['updated_at'] ?? 'now'))) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="mono">No pages yet.</div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
