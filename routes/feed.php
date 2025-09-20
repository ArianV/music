<?php
// routes/feed.php
// Public feed: shows trending or latest pages for everyone

require_once __DIR__ . '/../config.php';

// Guests can view the feed; no require_auth()
$limit = 24;

function fetch_trending_pages(int $limit = 24): array {
  $sql = "
    SELECT
      p.id,
      p.title,
      p.description,
      p.cover_url,
      u.handle,
      p.created_at,
      COALESCE(p.view_count, 0)  AS views,
      COALESCE(p.like_count, 0)  AS likes,
      (
        (COALESCE(p.like_count, 0) * 3 + COALESCE(p.view_count, 0))
        * EXP(
            - GREATEST(
                0,
                EXTRACT(EPOCH FROM (NOW() - COALESCE(p.updated_at, p.created_at))) / 86400.0
              ) / 7.0
          )
      ) AS score
    FROM pages p
    JOIN users u ON u.id = p.author_id
    WHERE COALESCE(p.is_public, TRUE) = TRUE
    ORDER BY score DESC NULLS LAST, p.created_at DESC
    LIMIT :n
  ";
  $st = db()->prepare($sql);
  $st->bindValue(':n', $limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

function fetch_latest_pages(int $limit = 24): array {
  $sql = "
    SELECT
      p.id,
      p.title,
      p.description,
      p.cover_url,
      u.handle,
      p.created_at,
      COALESCE(p.view_count, 0) AS views,
      COALESCE(p.like_count, 0) AS likes
    FROM pages p
    JOIN users u ON u.id = p.author_id
    WHERE COALESCE(p.is_public, TRUE) = TRUE
    ORDER BY p.created_at DESC
    LIMIT :n
  ";
  $st = db()->prepare($sql);
  $st->bindValue(':n', $limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

$pages = [];
$using_fallback = false;

try {
  $pages = fetch_trending_pages($limit);
} catch (Throwable $e) {
  error_log('[feed] trending query failed, fallback: ' . $e->getMessage());
  $pages = fetch_latest_pages($limit);
  $using_fallback = true;
}

// Public URL pattern: /u/{handle}
function page_public_url(array $p): string {
  return '/u/' . urlencode($p['handle']);
}

ob_start();
?>

<div class="feed-wrap">
  <h1><?= e($using_fallback ? 'Latest pages' : 'Trending pages') ?></h1>

  <?php if (!$pages): ?>
    <div class="notice">No public pages yet.</div>
  <?php else: ?>
    <div class="grid grid-cards">
      <?php foreach ($pages as $p): ?>
        <a class="card card-link" href="<?= e(page_public_url($p)) ?>">
          <?php if (!empty($p['cover_url'])): ?>
            <div class="card-media" style="background-image:url('<?= e($p['cover_url']) ?>');"></div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= e($p['title'] ?: 'Untitled') ?></div>
            <?php if (!empty($p['description'])): ?>
              <div class="card-meta muted"><?= e($p['description']) ?></div>
            <?php endif; ?>
            <div class="card-stats muted">
              <span>❤ <?= (int)($p['likes'] ?? 0) ?></span>
              <span>·</span>
              <span>👁 <?= (int)($p['views'] ?? 0) ?></span>
              <?php if (!empty($p['created_at'])): ?>
                <span>·</span>
                <span><?= e(date('M j, Y', strtotime($p['created_at']))) ?></span>
              <?php endif; ?>
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
