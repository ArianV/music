<?php
// routes/robots.php — /robots.txt: let search engines index public pages, skip private ones, point to the sitemap
require_once __DIR__ . '/../config.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
?>
User-agent: *
Disallow: /dashboard
Disallow: /analytics
Disallow: /settings
Disallow: /profile
Disallow: /pages/
Disallow: /go/
Allow: /

Sitemap: <?= asset('sitemap.xml') ?>

