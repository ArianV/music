<?php
// routes/feed.php
// Public feed: trending (or latest) pages for everyone, schema-aware.

require_once __DIR__ . '/../config.php';

// No require_auth(); feed is public.
$LIMIT = 24;

/**
 * Quick column checker so we can build SQL that matches your actual schema.
 */
function pg_has_column(string $table, string $col): bool {
  static $cache = [];
  $key = strtolower("$table.$col");
  if (isset($cache[$key])) return $cache[$key];

  $sql = "SELECT 1
            FROM information_schema.columns
           WHERE table_schema = 'public'
             AND table_name   = :t
             AND column_name  = :c
           LIMIT 1";
  $st = db()->prepare($sql);
  $st->execute([':t' => strtolower($table), ':c' => strtolower($col)]);
  $cache[$key] = (bool) $st->fetchColumn();
  return $cache[$key];
}

/**
 * Build a SELECT for pages with everything we might want, adapting to schema.
 * Returns ['sql' => string, 'params' => array].
 *
 * $mode: 'trending' | 'latest'
 */
function build_pages_query(string $mode, int $limit): array {
  // Which columns exist?
  $has_user_id    = pg_has_column('pages', 'user_id');
  $has_author_id  = pg_has_column('pages', 'author_id');
  $has_handle_col = pg_has_column('pages', 'handle');          // handle directly on pages?
  $has_cover      = pg_has_column('pages', 'cover_url');
  $has_desc       = pg_has_column('pages', 'description');
  $has_views      = pg_has_column('pages', 'view_count');
  $has_likes      = pg_has_column('pages', 'like_count');
  $has_is_public  = pg_has_column('pages', 'is_public');
  $has_updated_at = pg_has_column('pages', 'updated_at');
  $has_created_at = pg_has_column('pages', 'created_at');
  $has_title      = pg_has_column('pages', 'title');

  // Determine how to get the page owner's handle for /u/{handle}
  $join_users = false;
  $join_on    = null;     // 'user_id' or 'author_id'
  if (!$has_handle_col) {
    if ($has_user_id)   { $join_users = true; $join_on = 'user_id'; }
    elseif ($has_author_id) { $join_users = true; $join_on = 'author_id'; }
  }

  // SELECT list
  $sel = [];
  $sel[] = "p.id";
  $sel[] = $has_title ? "p.title" : "NULL AS title";
  $sel[] = $has_desc ? "p.description" : "NULL AS description";
  $sel[] = $has_cover ? "p.cover_url" : "NULL AS cover_url";
  $sel[] = $has_created_at ? "p.created_at" : "NOW()::timestamptz AS created_at";

  // views/likes always available as numbers, even if columns are missing
  $sel[] = $has_views ? "COALESCE(p.view_count, 0)  AS views" : "0::bigint AS views";
  $sel[] = $has_likes ? "COALESCE(p.like_count, 0)  AS likes" : "0::bigint AS likes";

  // handle to build /u/{handle}
  if ($has_handle_col) {
    $sel[] = "p.handle";
  } elseif ($join_users) {
    $sel[] = "u.handle";
  } else {
    // last resort (shouldn’t happen in your app): no handle available
    $sel[] = "NULL AS handle";
  }

  // Base FROM/WHERE/JOIN
  $from  = "FROM pages p";
  $joins = "";
  if ($join_users) {
    $joins = " JOIN users u ON u.id = p.$join_on ";
  }
  $where = "WHERE 1=1";
  if ($has_is_public) {
    $where .= " AND COALESCE(p.is_public, TRUE) = TRUE";
  }

  // Score for trending (time decay using likes+views if present)
  $time_col = $has_updated_at ? "COALESCE(p.updated_at, p.created_at)" : ($has_created_at ? "p.created_at" : "NOW()");
  $score = "(" .
    "(" . ($has_likes ? "COALESCE(p.like_count,0)" : "0") . " * 3 + " . ($has_views ? "COALESCE(p.view_count,0)" : "0") . ")" .
    " * EXP(- GREATEST(0, EXTRACT(EPOCH FROM (NOW() - $time_col)) / 86400.0) / 7.0)" .
  ")";

  // ORDER BY
  if ($mode === 'trending' && ($has_views || $has_likes)) {
    $order = "ORDER BY $score DESC NULLS LAST, " . ($has_created_at ? "p.created_at DESC" : "1");
    $sel[] = "$score AS score";
  } else {
    $order = "ORDER BY " . ($has_created_at ? "p.created_at DESC" : "1");
  }

  $sql = "SELECT " . implode(",\n       ", $sel) . "\n" .
         $from . "\n" .
         $joins . "\n" .
         $where . "\n" .
         $order . "\n" .
         "LIMIT :n";

  return ['sql' => $sql, 'params' => [':n' => $limit]];
}

/**
 * Fetch pages using mode with graceful fallback.
 */
function fetch_pages_safely(string $mode, int $limit): array {
  $q = build_pages_query($mode, $limit);
  $st = db()->prepare($q['sql']);
  $st->bindValue(':n', $limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll(PDO::FETCH_ASSOC);
}

$using_fallback = false;
try {
  $pages = fetch_pages_safely('trending', $LIMIT);
} catch (Throwable $e) {
  error_log('[feed] trending failed: ' . $e->getMessage());
  $pages = fetch_pages_safely('latest', $LIMIT);
  $using_fallback = true;
}

// Public URL pattern: /u/{handle}. If no handle, fall back to a generic page route.
function page_public_url(array $p): string {
  if (!empty($p['handle'])) {
    return '/u/' . urlencode($p['handle']);
  }
  // Fallbacks if handle is somehow unavailable:
  if (!empty($p['id'])) {
    // Adjust this to your actual page public route if different
    return '/page/' . urlencode($p['id']);
  }
  return '/';
}

ob_start();
?>

<div class="feed-wrap">
  <h1><?= e($using_fallback ? 'Latest pages' : 'Trending pages') ?></h1>

  <?php if (empty($pages)): ?>
    <div class="notice">No public pages yet.</div>
  <?php else: ?>
    <div class="grid grid-cards">
      <?php foreach ($pages as $p): ?>
        <a class="card card-link" href="<?= e(page_public_url($p)) ?>">
          <?php if (!empty($p['cover_url'])): ?>
            <div class="card-media" style="background-image:url('<?= e($p['cover_url']) ?>');"></div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= e(($p['title'] ?? '') !== '' ? $p['title'] : 'Untitled') ?></div>
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
