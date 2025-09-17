<?php
// routes/dashboard.php
require_once __DIR__ . '/../config.php';
require_auth();

$user = current_user();
$pdo  = db();

// Fetch pages newest first
$st = $pdo->prepare("
  SELECT id, title, slug, published, updated_at, created_at
  FROM pages
  WHERE user_id = :uid
  ORDER BY updated_at DESC NULLS LAST, id DESC
");
$st->execute([':uid' => $user['id']]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Helper to format time ago
if (!function_exists('time_ago')) {
  function time_ago($ts): string {
    if (!$ts) return '';
    $t = is_numeric($ts) ? (int)$ts : strtotime($ts);
    $d = time() - $t;
    foreach (['year'=>31536000,'month'=>2592000,'week'=>604800,'day'=>86400,'hour'=>3600,'min'=>60] as $name=>$secs) {
      if ($d >= $secs) { $n = floor($d/$secs); return "$n {$name}".($n>1?'s':'')." ago"; }
    }
    return 'just now';
  }
}

$title   = 'Dashboard';
$bodyCls = 'dashboard';

ob_start(); ?>
<div class="dash-header">
  <h1>Your Pages</h1>
  <a class="btn btn-primary" href="<?= e(asset('pages/new')) ?>">New Page</a>
</div>

<div class="table-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Title</th>
        <th>Slug</th>
        <th>Status</th>
        <th>Updated</th>
        <th class="actions">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr class="empty"><td colspan="5">No pages yet — create your first one!</td></tr>
    <?php else: foreach ($rows as $r):
      $slug   = $r['slug'] ?: (string)$r['id'];
      $pubUrl = rtrim(BASE_URL,'/').'/s/'.rawurlencode($slug);
      $editUrl= rtrim(BASE_URL,'/').'/pages/'.rawurlencode($slug).'/edit';
      $delUrl = rtrim(BASE_URL,'/').'/pages/'.rawurlencode($slug).'/delete';
    ?>
      <tr>
        <td data-label="Title"><?= e($r['title'] ?? 'Untitled') ?></td>
        <td data-label="Slug"><code><?= e($slug) ?></code></td>
        <td data-label="Status"><?= !empty($r['published']) ? 'Published' : 'Draft' ?></td>
        <td data-label="Updated"><?= e(time_ago($r['updated_at'] ?? $r['created_at'])) ?></td>
        <td class="actions" data-label="Actions">
          <a class="link" href="<?= e($pubUrl) ?>" target="_blank" rel="noopener">View</a>
          <span class="sep">•</span>
          <a class="link" href="<?= e($editUrl) ?>">Edit</a>
          <span class="sep">•</span>
          <a class="link danger" href="<?= e($delUrl) ?>" onclick="return confirm('Delete this page?')">Delete</a>
          <span class="sep">•</span>
          <button type="button" class="btn copy-btn" data-url="<?= e($pubUrl) ?>">Copy link</button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
document.addEventListener('click', function(e){
  const b = e.target.closest('.copy-btn');
  if (!b) return;
  const url = b.dataset.url || '';
  if (!url) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(() => {
      const old = b.textContent;
      b.textContent = 'Copied!';
      b.disabled = true;
      setTimeout(() => { b.textContent = old; b.disabled = false; }, 1200);
    }).catch(() => {
      window.prompt('Copy link', url);
    });
  } else {
    window.prompt('Copy link', url);
  }
});
</script>
<?php
$content = ob_get_clean();

require __DIR__ . '/../views/layout.php';
