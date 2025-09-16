<?php require_once __DIR__ . '/../config.php'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title ?? 'Music Landing') ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Absolute URL to CSS so routing paths can’t break it -->
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css')) ?>?v=1">
  <?= $head ?? '' ?>
</head>
<body class="<?= e($bodyCls ?? '') ?>">
  
  <header class="site-header">
    <a class="brand" href="<?= BASE_URL ?>">Music Landing</a>
    <nav>
      <?php if ($u): ?>
        <a href="<?= BASE_URL ?>dashboard">Dashboard</a>
        <a href="<?= BASE_URL ?>logout">Logout</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>login">Login</a>
        <a href="<?= BASE_URL ?>register">Register</a>
      <?php endif; ?>
    </nav>
  </header>
  
  <?= $content ?? '' ?>
</body>
</html>
