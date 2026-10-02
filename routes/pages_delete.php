<?php
// routes/pages_delete.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
$key  = (string)($GLOBALS['page_id'] ?? '');
if ($key === '') not_found();

$pdo = db();

// Load row scoped to owner
if (ctype_digit($key)) {
  $st = $pdo->prepare("SELECT * FROM pages WHERE id = :id AND user_id = :uid");
  $st->execute([':id' => (int)$key, ':uid' => $user['id']]);
} else {
  $st = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND user_id = :uid");
  $st->execute([':slug' => $key, ':uid' => $user['id']]);
}
$page = $st->fetch();
if (!$page) not_found();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pdo->prepare("DELETE FROM pages WHERE id = :id AND user_id = :uid")
      ->execute([':id' => (int)$page['id'], ':uid' => $user['id']]);

  header('Location: ' . asset('dashboard'));
  exit;
}

$title = 'Delete page · PlugBio';
ob_start(); ?>
<div class="card narrow" style="max-width:480px;margin-top:40px">
  <h1 style="font-size:24px">Delete “<?= e($page['title'] ?? 'Untitled') ?>”</h1>
  <p class="muted">The page, its link and its stats will be removed. This can’t be undone.</p>
  <form method="post" class="form-actions">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button type="submit" class="btn btn-danger">Delete page</button>
    <a class="link" href="<?= e(asset('dashboard')) ?>">Cancel</a>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
