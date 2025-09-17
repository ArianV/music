<?php
// routes/profile_view.php
require_once __DIR__ . '/../config.php';

$pdo = db();
$me  = current_user();

$handle = $GLOBALS['handle'] ?? '';
if ($handle === '') { http_response_code(404); exit('Not found'); }

// load the profile owner
$st = $pdo->prepare("SELECT * FROM users WHERE lower(handle) = lower(:h) LIMIT 1");
$st->execute([':h' => $handle]);
$owner = $st->fetch(PDO::FETCH_ASSOC);
if (!$owner) { http_response_code(404); exit('User not found'); }

$isOwner = $me && ((int)$me['id'] === (int)$owner['id']);
$isPublic = (bool)($owner['profile_public'] ?? false);

if (!$isPublic && !$isOwner) {
  // private profile view
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

// fetch owner’s published pages
$q = "SELECT id, title, slug, cover_uri, links_json, updated_at
      FROM pages
      WHERE user_id = :uid";
$params = [':uid' => (int)$owner['id']];
if (!$isOwner) { $q .= " AND published = TRUE"; }
$q .= " ORDER BY updated_at DESC NULLS LAST, id DESC";
$st = $pdo->prepare($q);
$st->execute($params);
$pages = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$avatar = $owner['avatar_uri'] ?? null;
$display = $owner['display_name'] ?? $owner['handle'];
$title = $display . ' • Profile';

$head = <<<CSS
<style>
.profile{max-width:900px;margin:24px auto}
.header{display:flex;gap:16px;align-items:center;margin-bottom:18px}
.pfp{width:80px;height:80px;border-radius:999px;object-fit:cover;border:1px solid #263142;background:#0f1217}
.mono{color:#a1a1aa;font-size:12px}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px}
.card-page{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:12px}
.card-page img{width:100%;height:160px;object-fit:cover;border-radius:8px;border:1px solid #253041;background:#0f1217}
.btn{display:inline-flex;align-items:center;gap:8px;background:#1f2937;color:#e5e7eb;border:1px solid #2a3344;border-radius:10px;padding:8px 12px;text-decoration:none}
.btn:hover{background:#232e44}
</style>
CSS;

ob_start(); ?>
<div class="profile">
  <div class="header">
    <img class="pfp" src="<?= e($avatar ?: 'data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22><rect width=%2264%22 height=%2264%22 rx=%2212%22 fill=%22%230B0B0C%22/></svg>') ?>" alt="">
    <div>
      <h1 style="margin:0 0 6px"><?= e($display) ?></h1>
      <div class="mono">@<?= e($owner['handle'] ?? '') ?></div>
    </div>
    <div style="flex:1"></div>
    <?php if ($isOwner): ?>
      <a class="btn" href="<?= e(asset('profile')) ?>">Edit profile</a>
    <?php endif; ?>
  </div>

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
