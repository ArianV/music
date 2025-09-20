<?php
// routes/feed.php — Latest/Trending feed using your columns
// pages: id, user_id, title, artist_name, cover_uri, links_json, slug, published, created_at, updated_at

require_once __DIR__ . '/../config.php';

// Public feed; no auth required.
$LIMIT = 24;

// Query newest public pages (you can later switch to a trending score)
$sql = "
  SELECT
    p.id,
    p.title,
    p.artist_name,
    p.slug,
    p.cover_uri,
    COALESCE(p.updated_at, p.created_at) AS ts,
    u.handle
  FROM pages p
  JOIN users u ON u.id = p.user_id
  WHERE COALESCE(p.published, TRUE) = TRUE
  ORDER BY COALESCE(p.updated_at, p.created_at) DESC
  LIMIT :n
";
$st = db()->prepare($sql);
$st->bindValue(':n', $LIMIT, PDO::PARAM_INT);
$st->execute();
$pages = $st->fetchAll(PDO::FETCH_ASSOC);

// Link to /u/{handle}
function page_public_url(array $p): string {
  return '/u/' . urlencode($p['handle']);
}

// helper: initial for placeholder
function initial_for(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? strtoupper(mb_substr($s, 0, 1)) : '♪';
}

ob_start();
?>
<div class="feed-wrap">
  <h1>Trending pages</h1>

  <?php if (!$pages): ?>
    <div class="notice">No public pages yet.</div>
  <?php else: ?>
    <div class="grid grid-cards">
      <?php foreach ($pages as $p): ?>
        <a class="card-link" href="<?= e(page_public_url($p)) ?>">
          <?php if (!empty($p['cover_uri'])): ?>
            <div class="card-media" style="background-image:url('<?= e($p['cover_uri']) ?>');"></div>
          <?php else: ?>
            <div class="card-media placeholder"><?= e(initial_for($p['artist_name'] ?? $p['title'] ?? $p['handle'] ?? '')) ?></div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= e($p['title'] ?: 'Untitled') ?></div>
            <?php if (!empty($p['artist_name'])): ?>
              <div class="card-meta muted"><?= e($p['artist_name']) ?></div>
            <?php endif; ?>

            <?php
              // simple stats/date row; hide if nothing meaningful
              $date = !empty($p['ts']) ? date('M j, Y', strtotime($p['ts'])) : null;
              $isEmpty = $date ? "0" : "1";
            ?>
            <div class="card-stats muted" data-empty="<?= $isEmpty ?>">
              <?php if ($date): ?><span><?= e($date) ?></span><?php endif; ?>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
