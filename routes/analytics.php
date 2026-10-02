<?php
// routes/analytics.php — simple creator analytics
require_once __DIR__ . '/../config.php';
require_auth();

$me = current_user();

// Per page: unique views (one per visitor per day) for 7d / 30d / all time, plus link clicks.
$sql = "
  WITH v AS (
    SELECT pv.page_id,
           count(DISTINCT pv.session_key || date(pv.created_at)) FILTER (WHERE pv.created_at >= datetime('now', '-7 days'))  AS views_7d,
           count(DISTINCT pv.session_key || date(pv.created_at)) FILTER (WHERE pv.created_at >= datetime('now', '-30 days')) AS views_30d,
           count(DISTINCT pv.session_key || date(pv.created_at))                                                             AS views_total
    FROM page_views pv JOIN pages p ON p.id = pv.page_id
    WHERE p.user_id = :uid
    GROUP BY pv.page_id
  ), c AS (
    SELECT pc.page_id,
           count(*) FILTER (WHERE pc.created_at >= datetime('now', '-30 days')) AS clicks_30d,
           count(*)                                                            AS clicks_total
    FROM page_clicks pc JOIN pages p ON p.id = pc.page_id
    WHERE p.user_id = :uid
    GROUP BY pc.page_id
  )
  SELECT p.id, p.title, p.slug,
         COALESCE(v.views_7d, 0)     AS views_7d,
         COALESCE(v.views_30d, 0)    AS views_30d,
         COALESCE(v.views_total, 0)  AS views_total,
         COALESCE(c.clicks_30d, 0)   AS clicks_30d,
         COALESCE(c.clicks_total, 0) AS clicks_total
  FROM pages p
  LEFT JOIN v ON v.page_id = p.id
  LEFT JOIN c ON c.page_id = p.id
  WHERE p.user_id = :uid
  ORDER BY COALESCE(v.views_7d, 0) DESC, p.updated_at DESC
";
$st = db()->prepare($sql);
$st->execute([':uid' => $me['id']]);
$rows = $st->fetchAll();

// top 5 referrers (last 30d)
$ref = db()->prepare("
  SELECT ref_host, count(*) AS hits
  FROM page_views pv
  JOIN pages p ON p.id = pv.page_id
  WHERE p.user_id = :uid
    AND pv.created_at >= datetime('now', '-30 days')
    AND pv.ref_host IS NOT NULL
  GROUP BY ref_host
  ORDER BY hits DESC
  LIMIT 5
");
$ref->execute([':uid' => $me['id']]);
$refs = $ref->fetchAll();

// top 5 links clicked (last 30d)
$top = db()->prepare("
  SELECT p.title, pc.url, count(*) AS hits
  FROM page_clicks pc
  JOIN pages p ON p.id = pc.page_id
  WHERE p.user_id = :uid
    AND pc.created_at >= datetime('now', '-30 days')
  GROUP BY p.title, pc.url
  ORDER BY hits DESC
  LIMIT 5
");
$top->execute([':uid' => $me['id']]);
$topLinks = $top->fetchAll();

$title = 'Analytics · PlugBio';
$sum = fn(string $k) => array_sum(array_map(fn($r) => (int)$r[$k], $rows));
$views30 = $sum('views_30d');
$clicks30 = $sum('clicks_30d');
$ctr = $views30 ? round($clicks30 / $views30 * 100) . '%' : '—';

ob_start();
?>
<div class="page-head">
  <div>
    <h1>Analytics</h1>
    <p>Views count each visitor once per day. Your own visits aren’t counted.</p>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="empty-state">
    <h3>No data yet</h3>
    <p>Create and share a page to start seeing views and clicks.</p>
    <a class="btn btn-primary" href="<?= e(asset('pages/new')) ?>">Create a page</a>
  </div>
<?php else: ?>
  <div class="stats">
    <div class="stat"><div class="label">Views · 7 days</div><div class="value"><?= number_format($sum('views_7d')) ?></div></div>
    <div class="stat"><div class="label">Views · 30 days</div><div class="value"><?= number_format($views30) ?></div></div>
    <div class="stat"><div class="label">Link clicks · 30 days</div><div class="value gradient-text"><?= number_format($clicks30) ?></div></div>
    <div class="stat"><div class="label">Click-through rate</div><div class="value"><?= e($ctr) ?></div></div>
  </div>

  <div class="table-wrap">
    <table class="tbl">
      <thead>
        <tr>
          <th>Page</th>
          <th class="num">Views 7d</th>
          <th class="num">Views 30d</th>
          <th class="num">Views total</th>
          <th class="num">Clicks 30d</th>
          <th class="num">Clicks total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td data-label="Page" class="title-cell"><a href="<?= e(page_url($r)) ?>"><?= e($r['title'] ?: 'Untitled') ?></a></td>
            <td data-label="Views 7d" class="num"><?= (int)$r['views_7d'] ?></td>
            <td data-label="Views 30d" class="num"><?= (int)$r['views_30d'] ?></td>
            <td data-label="Views total" class="num"><?= (int)$r['views_total'] ?></td>
            <td data-label="Clicks 30d" class="num"><?= (int)$r['clicks_30d'] ?></td>
            <td data-label="Clicks total" class="num"><?= (int)$r['clicks_total'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="two-col">
    <div class="card">
      <h3>Top links · 30 days</h3>
      <?php if (!$topLinks): ?>
        <p class="muted" style="margin:0">No link clicks yet.</p>
      <?php else: ?>
        <ul class="rank">
          <?php foreach ($topLinks as $x): ?>
            <li>
              <span><?= e(service_label(detect_service($x['url'])) ?? parse_url($x['url'], PHP_URL_HOST)) ?>
                <span class="muted small">· <?= e($x['title'] ?: 'Untitled') ?></span></span>
              <span class="count"><?= (int)$x['hits'] ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>Top referrers · 30 days</h3>
      <?php if (!$refs): ?>
        <p class="muted" style="margin:0">No referrers yet. Visits from shared links will show up here.</p>
      <?php else: ?>
        <ul class="rank">
          <?php foreach ($refs as $x): ?>
            <li><span><?= e($x['ref_host']) ?></span><span class="count"><?= (int)$x['hits'] ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
