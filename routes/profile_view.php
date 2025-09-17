<?php
// routes/profile_view.php
require_once __DIR__ . '/../config.php';

$pdo    = db();
$handle = $GLOBALS['handle'] ?? ($_GET['handle'] ?? null);
if (!$handle) { http_response_code(404); exit('Not found'); }

/* artist by handle (case-insensitive) */
$st = $pdo->prepare("SELECT * FROM users WHERE lower(handle)=lower(:h) LIMIT 1");
$st->execute([':h' => $handle]);
$artist = $st->fetch(PDO::FETCH_ASSOC);
if (!$artist) { http_response_code(404); exit('Not found'); }

/* viewer & visibility */
$viewer_id = (int)($GLOBALS['__current_user_id'] ?? (current_user()['id'] ?? 0));
$is_owner  = $viewer_id && $viewer_id === (int)$artist['id'];
$is_public = (bool)($artist['profile_public'] ?? false);

if (!$is_public && !$is_owner) {
  // Private profile for the public
  http_response_code(403);
  $title = 'Private profile';
  $head  = '<meta name="robots" content="noindex">';
  ob_start(); ?>
  <article class="card" style="max-width:420px;margin:24px auto;text-align:center;padding:28px">
    <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:14px;background:#0f1217;border:1px solid #2a3344;margin-bottom:12px">
      <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
        <path d="M7 10V8a5 5 0 0 1 10 0v2m-9 0h8a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2Z"
              stroke="#a1a1aa" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </div>
    <h1 class="title" style="margin:0 0 6px">This profile is private</h1>
    <p class="artist" style="margin:0 0 14px">The artist hasn’t made their profile public.</p>
  </article>
  <?php
  $content = ob_get_clean();
  require __DIR__ . '/../views/layout.php';
  exit;
}

/* render data */
$display = $artist['display_name'] ?: $artist['handle'];
$bio     = $artist['bio'] ?? '';
$avatar  = $artist['avatar_uri'] ?? null;
$socials = json_decode($artist['socials_json'] ?? '[]', true) ?: [];

/* helper: get column data type */
$published_filter = '';
if (table_has_column($pdo, 'pages', 'published')) {
  $typeStmt = $pdo->prepare("
    SELECT data_type
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'pages' AND column_name = 'published'
    LIMIT 1
  ");
  $typeStmt->execute();
  $dt = strtolower((string)($typeStmt->fetchColumn() ?: ''));
  if ($dt === 'boolean') {
    $published_filter = " AND published IS TRUE";
  } else {
    $published_filter = " AND published = 1";
  }
}

/* pages: only published (owner also sees published list here; drafts remain private via /dashboard) */
$order = table_has_column($pdo,'pages','updated_at') ? 'updated_at DESC NULLS LAST, id DESC' : 'id DESC';
$sql = "SELECT * FROM pages WHERE user_id=:uid".$published_filter." ORDER BY $order";
$st  = $pdo->prepare($sql);
$st->execute([':uid'=>$artist['id']]);
$pages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* banner if owner & private */
$banner = '';
if (!$is_public && $is_owner) {
  $banner = '<div style="max-width:820px;margin:8px auto 0;padding:8px 12px;border:1px solid #8b5cf6;border-radius:10px;background:#151325;color:#e5e7eb;font-size:13px">'
          . 'Your profile is <b>private</b>. Toggle it on your <a class="link" href="'.e(asset('profile')).'" style="color:#a78bfa">Profile</a> page.'
          . '</div>';
}

/* page chrome */
$title = $display . ' · PlugBio';
$head  = '';

function social_icon(string $k): string {
  $map = ['website'=>'🌐','twitter'=>'𝕏','instagram'=>'◎','tiktok'=>'🎵','youtube'=>'▶','soundcloud'=>'☁','bandcamp'=>'⏵','spotify'=>'🟢','apple'=>''];
  return $map[$k] ?? '↗';
}

ob_start(); ?>
<article class="card" style="max-width:820px;margin:24px auto;">
  <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
    <img src="<?= e($avatar ?: 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect width=%2264%22 height=%2264%22 rx=%2212%22 fill=%22%230B0B0C%22/></svg>') ?>"
         class="avatar" style="width:88px;height:88px;border-radius:50%;object-fit:cover;border:1px solid #263142;background:#0f1217" alt="">
    <div style="min-width:220px">
      <h1 class="title" style="margin:0 0 4px"><?= e($display) ?></h1>
      <div class="small" style="color:#a1a1aa">@<?= e($artist['handle']) ?></div>
      <?php if ($bio): ?><p style="margin:8px 0 0;white-space:pre-line"><?= e($bio) ?></p><?php endif; ?>
      <?php if ($socials): ?>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">
          <?php foreach ($socials as $k=>$u): if (!$u) continue; ?>
            <a class="btn" style="padding:6px 10px" href="<?= e($u) ?>" target="_blank" rel="noopener"><?= social_icon($k) ?> <?= e(ucfirst($k)) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($pages): ?>
    <div style="margin-top:18px">
      <h2 style="font-size:18px;margin:0 0 8px">Releases</h2>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px">
        <?php foreach ($pages as $p):
          $slug  = ($p['slug'] ?? '') !== '' ? $p['slug'] : $p['id'];
          $cover = page_cover($p);
          $url   = rtrim(BASE_URL,'/').'/s/'.rawurlencode($slug);
        ?>
          <a class="card" href="<?= e($url) ?>" style="text-decoration:none">
            <?php if ($cover): ?>
              <img src="<?= e($cover) ?>" alt="" style="width:100%;height:150px;object-fit:cover;border-radius:10px">
            <?php else: ?>
              <div style="width:100%;height:150px;border:1px solid #263142;border-radius:10px;background:#0f1217"></div>
            <?php endif; ?>
            <div style="padding:10px 8px">
              <div class="title" style="font-size:16px;margin:0"><?= e($p['title'] ?? 'Untitled') ?></div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <p style="margin-top:16px;color:#9ca3af">No published pages yet.</p>
  <?php endif; ?>
</article>
<?= $banner ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
