<?php $u = current_user(); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title ?? 'Music Landing') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/styles.css">
  <?= $head ?? '' ?>
</head>
<body class="layout">
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

  <main class="container">
    <?= $content ?? '' ?>
  </main>

  <footer class="site-footer">© <?= date('Y') ?> Music Landing</footer>
</body>
</html>
