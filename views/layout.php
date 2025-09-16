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
  <?= $content ?? '' ?>
</body>
</html>
