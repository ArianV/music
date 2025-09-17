<?php
require_once __DIR__ . '/../config.php';

$pdo = db();
$handle = $GLOBALS['handle'] ?? null;
if (!$handle) {
  // also allow /u?handle=...
  $handle = $_GET['handle'] ?? null;
}
if (!$handle) { http_response_code(404); exit('Not found'); }

/* find the user by handle (case-insensitive) */
$st = $pdo->prepare("SELECT * FROM users WHERE lower(handle)=lower(:h) LIMIT 1");
$st->execute([':h'=>$handle]);
$artist = $st->fetch(PDO::FETCH_ASSOC);
if (!$artist) { http_response_code(404); exit('Not found'); }

$display = $artist['display_name'] ?: $artist['handle'];
$bio     = $artist['bio'] ?? '';
$avatar  = $artist['avatar_uri'] ?? null;
$socials = json_decode($artist['socials_json'] ?? '[]', true) ?: [];

/* fetch published pages for this user */
$pages = [];
$st = $pdo->prepare("SELECT id, title, slug, cover_uri, cover_url, cover_image, updated_at
                     FROM pages
                     WHERE user_id=:uid AND COALESCE(published,0)=1
                     ORDER BY updated_at DESC NULLS LAST, id DESC");
$st->execute([':uid'=>$artist['id']]);
$pages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$title = $display . ' · PlugBio';
$head  = ''; // add OG tags if you want

function social_icon(string $k): string {
  $map = [
    'website'=>'🌐','twitter'=>'𝕏','instagram'=>'◎','tiktok'=>'🎵','youtube'=>'▶',
    'soundcloud'=>'☁','bandcamp'=>'⏵','spotify'=>'🟢','apple'=>''
  ];
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
          $slug = $p['slug'] ?: $p['id'];
          $cover= $p['cover_uri'] ?? $p['cover_url'] ?? $p['cover_image'] ?? null;
          $url  = rtrim(BASE_URL,'/').'/s/'.rawurlencode($slug);
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
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
