<?php
$username = $_GET['username'] ?? '';
$slug     = $_GET['slug'] ?? '';

if ($username === '' || $slug === '') {
  http_response_code(404);
  exit('Not found');
}

// Find user (case-insensitive)
$uStmt = db()->prepare('SELECT id, username FROM users WHERE lower(username) = lower(:username) LIMIT 1');
$uStmt->execute([':username' => $username]);
$user = $uStmt->fetch();
if (!$user) { http_response_code(404); exit('User not found'); }

// Find published page for that user + slug
$pStmt = db()->prepare('
  SELECT * FROM pages
  WHERE user_id = :uid AND slug = :slug AND published = true
  LIMIT 1
');
$pStmt->execute([':uid' => (int)$user['id'], ':slug' => $slug]);
$page = $pStmt->fetch();
if (!$page) { http_response_code(404); exit('Page not found'); }

$links = json_decode($page['links_json'] ?: '[]', true) ?? [];

$title = $page['title'] . ' — ' . $page['artist_name'];
$head = '
  <meta property="og:title" content="'.e($title).'">
  <meta property="og:type" content="website">
  <meta property="og:image" content="'.e($page['cover_url']).'">
  <meta property="og:url" content="'.e(BASE_URL).'@'.e($user['username']).'/'.e($page['slug']).'">
  <meta name="twitter:card" content="summary_large_image">
  <style> body{background:'.e($page['bg_color'] ?: '#0b0b10').'} </style>
';

ob_start(); ?>
<div class="card" style="text-align:center">
  <?php if (!empty($page['cover_url'])): ?>
    <img src="<?= e($page['cover_url']) ?>" class="cover" alt="Cover">
  <?php endif; ?>
  <h1><?= e($page['title']) ?></h1>
  <p class="small"><?= e($page['artist_name']) ?></p>
  <div class="link-list">
    <?php foreach ($links as $i => $link): ?>
      <?php
        $id  = isset($link['id']) ? (int)$link['id'] : ($i + 1);
        $url = $link['url'] ?? '#';
      ?>
      <div class="link-item">
        <span><?= e($link['label'] ?? 'Link') ?></span>
        <a class="btn" href="<?= BASE_URL . 'r/' . $id . '?to=' . urlencode($url) ?>" target="_blank";>Open</a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
