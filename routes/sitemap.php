<?php
// routes/sitemap.php — /sitemap.xml: every public page and profile, so search engines can find them
require_once __DIR__ . '/../config.php';

$pdo   = db();
$pages = $pdo->query("SELECT id, slug, updated_at FROM pages WHERE published ORDER BY updated_at DESC")->fetchAll();
$users = $pdo->query("SELECT u.handle, max(p.updated_at) AS updated_at
                      FROM users u JOIN pages p ON p.user_id = u.id AND p.published
                      WHERE u.profile_public AND u.handle IS NOT NULL
                      GROUP BY u.id")->fetchAll();

$lastmod = fn(?string $ts) => $ts ? gmdate('Y-m-d', strtotime($ts)) : null;
$urls = [['loc' => asset(''), 'lastmod' => $lastmod($pages[0]['updated_at'] ?? null)]];
foreach ($pages as $p) $urls[] = ['loc' => page_url($p), 'lastmod' => $lastmod($p['updated_at'])];
foreach ($users as $u) $urls[] = ['loc' => asset('u/' . rawurlencode($u['handle'])), 'lastmod' => $lastmod($u['updated_at'])];

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as $u) {
  echo '  <url><loc>', e($u['loc']), '</loc>', $u['lastmod'] ? '<lastmod>' . $u['lastmod'] . '</lastmod>' : '', "</url>\n";
}
echo "</urlset>\n";
