<?php
$u = current_user();
$slug = $_GET['slug'] ?? '';

$stmt = db()->prepare('SELECT id FROM pages WHERE user_id = :uid AND slug = :slug LIMIT 1');
$stmt->execute([':uid' => (int)$u['id'], ':slug' => $slug]);
$page = $stmt->fetch();

if (!$page) {
    http_response_code(404);
    exit('Page not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
        http_response_code(422);
        exit('Invalid CSRF token');
    }
    $del = db()->prepare('DELETE FROM pages WHERE id = :id AND user_id = :uid');
    $del->execute([':id' => (int)$page['id'], ':uid' => (int)$u['id']]);
    header('Location: ' . BASE_URL . 'dashboard');
    exit;
}

$title = 'Delete Page';
ob_start(); ?>
<div class="card">
  <h2>Delete Page</h2>
  <p>Are you sure you want to delete <strong><?= e($slug) ?></strong>? This cannot be undone.</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <button class="btn" style="background:#f87171">Yes, Delete</button>
    <a class="btn" href="<?= BASE_URL ?>dashboard">Cancel</a>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
