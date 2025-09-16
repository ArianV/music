<?php
// views/layout.php
require_once __DIR__ . '/../config.php';

$title    = $title    ?? 'Music Landing';
$head     = $head     ?? '';   // extra <head> tags if a page wants to inject
$bodyCls  = $bodyCls  ?? '';
$hideHeader = $hideHeader ?? false; // set true on pages that don't want the header

$u = current_user();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Use absolute URL so routing paths can’t break it -->
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css')) ?>">
  <?= $head ?>
  <style>
    /* minimal header styling in case your CSS doesn't cover it */
    .site-header{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#111318;border-bottom:1px solid #1f2430}
    .site-brand{font-weight:700;color:#64f0be;text-decoration:none}
    .site-nav a{color:#93c5fd;text-decoration:none;margin-left:14px}
    .container{max-width:960px;margin:40px auto;padding:0 16px}
  </style>
</head>
<body class="<?= e($bodyCls) ?>">

<?php if (!$hideHeader): ?>
  <header class="site-header">
    <a class="site-brand" href="<?= e(BASE_URL) ?>">MusicPages</a>
    <nav class="site-nav">
      <?php if ($u): ?>
        <a href="<?= e(BASE_URL) ?>dashboard">Dashboard</a>
        <a href="<?= e(BASE_URL) ?>pages/new">New Page</a>
        <a href="<?= e(BASE_URL) ?>logout">Logout</a>
      <?php else: ?>
        <a href="<?= e(BASE_URL) ?>login">Login</a>
        <a href="<?= e(BASE_URL) ?>register">Register</a>
      <?php endif; ?>
    </nav>
  </header>
<?php endif; ?>

<main class="container">
  <?= $content ?? '' ?>
</main>

</body>
</html>
