<?php
// routes/dashboard.php
require_once __DIR__ . '/../config.php';
require_auth();

$u = current_user();
$pdo = db();

// derive a handle for public URLs (best-effort)
$handle = null;
foreach (['username','handle','slug','name'] as $col) {
  if ($handle) break;
  if (table_has_column($pdo, 'users', $col) && !empty($u[$col])) {
    $handle = strtolower(trim($u[$col]));
  }
}
if (!$handle) { $handle = 'me'; } // fallback (still shows /pages/... links)

// fetch pages
$st = $pdo->prepare("
  SELECT id, title, slug, published,
         COALESCE(updated_at, created_at) AS ts,
         created_at, updated_at
  FROM pages
  WHERE user_id = :uid
  ORDER BY updated_at DESC NULLS LAST, created_at DESC NULLS LAST, id DESC
");
$st->execute([':uid' => $u['id']]);
$rows = $st->fetchAll();

ob_start();
?>
<div class="dash-header">
  <h1>Your Pages</h1>
  <a class="btn btn-primary" href="<?= e(BASE_URL) ?>pages/new">New Page</a>
</div>

<div class="table-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Title</th>
        <th>Slug</th>
        <th>Status</th>
        <th>Updated</th>
        <th>Public URL</th>
        <th class="actions">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $slug   = $r['slug'] ?: (string)$r['id'];
      $pubUrl = rtrim(BASE_URL,'/').'/s/'.rawurlencode($slug);
      // fallback to /pages/{slug} if you prefer:
      // $pubUrl = BASE_URL . 'pages/' . rawurlencode($slug);
    ?>
      <tr>
        <td data-label="Title">
          <strong><?= e($r['title'] ?: 'Untitled') ?></strong>
        </td>
        <td data-label="Slug">
          <code><?= e($slug) ?></code>
        </td>
        <td data-label="Status">
          <?= $r['published'] ? 'Published' : 'Private' ?>
        </td>
        <td data-label="Updated">
          <?= e(time_ago($r['ts'] ?? null) ?: '—') ?>
        </td>
        <td data-label="Public URL">
          <a class="link" href="<?= e($pubUrl) ?>" target="_blank" rel="noopener">View</a>
        </td>
        <td data-label="Actions" class="actions">
          <a class="link" href="<?= e(BASE_URL) . 'pages/' . e($slug) ?>/edit">Edit</a>
          <span class="sep">|</span>
          <a class="link danger" href="<?= e(BASE_URL) . 'pages/' . (int)$r['id'] ?>/delete">Delete</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?>
      <tr class="empty"><td colspan="6">No pages yet. Create your first one!</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>
<?php
$content = ob_get_clean();
$title = 'Dashboard · Music Landing';
$bodyCls = 'dashboard';
require __DIR__ . '/../views/layout.php';
