<?php
$u = current_user();
require_auth(); // safety

$stmt = db()->prepare('
  SELECT id, title, slug, published, updated_at
  FROM pages
  WHERE user_id = :uid
  ORDER BY updated_at DESC
');
$stmt->execute([':uid' => (int)$u['id']]);
$pages = $stmt->fetchAll();

$title = 'Dashboard';
ob_start(); ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <h2>Your Pages</h2>
    <a class="btn" href="<?= BASE_URL ?>pages/new">New Page</a>
  </div>
  <?php if (empty($pages)): ?>
    <p class="small">No pages yet. Create your first one!</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Updated</th><th>Public URL</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pages as $p): ?>
          <tr>
            <td><?= e($p['title']) ?></td>
            <td><?= e($p['slug']) ?></td>
            <td><?= !empty($p['published']) ? 'Published' : 'Draft' ?></td>
            <td><?= e(time_ago($p['updated_at'])) ?></td>
            <td>
              <?php if (!empty($p['published'])): ?>
                <a href="<?= BASE_URL . '@' . e($u['username']) . '/' . e($p['slug']) ?>" target="_blank">View</a>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <a href="<?= BASE_URL . 'pages/' . e($p['slug']) . '/edit' ?>">Edit</a> |
              <a href="<?= BASE_URL . 'pages/' . e($p['slug']) . '/delete' ?>" style="color:#f87171">Delete</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
