<?php
// routes/analytics.php — simple creator analytics
require_once __DIR__ . '/../config.php';
require_auth();

$me = current_user();

// List the creator's pages + 7d/30d/total views
$sql = "
  SELECT
    p.id,
    p.title,
    p.slug,
    COALESCE(t.views_total, 0)  AS views_total,
    COALESCE(d7.views_7d, 0)    AS views_7d,
    COALESCE(d30.views_30d, 0)  AS views_30d
  FROM pages p
  LEFT JOIN page_view_totals t ON t.page_id = p.id
  LEFT JOIN page_view_last_7 d7 ON d7.page_id = p.id
  LEFT JOIN page_view_last_30 d30 ON d30.page_id = p.id
  WHERE p.user_id = :uid
  ORDER BY COALESCE(d7.views_7d,0) DESC, p.updated_at DESC
";
$st = db()->prepare($sql);
$st->execute([':uid' => $me['id']]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// top 5 referrers (last 30d)
$ref = db()->prepare("
  SELECT ref_host, count(*)::bigint AS hits
  FROM page_views pv
  JOIN pages p ON p.id = pv.page_id
  WHERE p.user_id = :uid
    AND pv.created_at >= now() - interval '30 days'
    AND pv.ref_host IS NOT NULL
  GROUP BY ref_host
  ORDER BY hits DESC
  LIMIT 5
");
$ref->execute([':uid'=>$me['id']]);
$refs = $ref->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="sets-wrap">
  <h1>Analytics</h1>

  <?php if (!$rows): ?>
    <div class="notice">No pages yet.</div>
  <?php else: ?>
    <div class="card">
      <div class="row" style="overflow:auto">
        <table class="table" style="width:100%;border-collapse:collapse">
          <thead>
            <tr class="muted">
              <th style="text-align:left;padding:8px 6px;">Page</th>
              <th style="text-align:right;padding:8px 6px;">7 days</th>
              <th style="text-align:right;padding:8px 6px;">30 days</th>
              <th style="text-align:right;padding:8px 6px;">Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td style="padding:8px 6px;">
                  <a href="<?= e(!empty($r['slug']) ? '/page/'.urlencode($r['slug']) : '/page?id='.$r['id']) ?>">
                    <?= e($r['title'] ?: 'Untitled') ?>
                  </a>
                </td>
                <td style="text-align:right;padding:8px 6px;"><?= (int)$r['views_7d'] ?></td>
                <td style="text-align:right;padding:8px 6px;"><?= (int)$r['views_30d'] ?></td>
                <td style="text-align:right;padding:8px 6px;"><?= (int)$r['views_total'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card" style="margin-top:16px">
      <div class="row">
        <h3 style="margin:0 0 8px">Top referrers (30d)</h3>
        <?php if (!$refs): ?>
          <div class="muted">No referrers yet.</div>
        <?php else: ?>
          <ul style="margin:0;padding-left:18px">
            <?php foreach ($refs as $x): ?>
              <li><?= e($x['ref_host']) ?> — <?= (int)$x['hits'] ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
