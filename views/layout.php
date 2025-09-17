<?php
// views/layout.php
// Assumes routes already required config.php. Still, add tiny safeties:

if (!function_exists('e')) {
  function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!defined('APP_NAME')) define('APP_NAME', 'PlugBio');
if (!function_exists('app_name')) {
  function app_name(): string { return defined('APP_NAME') ? APP_NAME : 'PlugBio'; }
}

// asset() helper (prefix BASE_URL)
if (!function_exists('asset')) {
  function asset(string $path): string {
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    return $base . '/' . ltrim($path, '/');
  }
}

// auth helper might already exist; if not, stub to null
if (!function_exists('current_user')) {
  function current_user(): ?array { return null; }
}

$pageTitle = isset($title) && $title ? $title : app_name();
$bodyClass = isset($bodyCls) ? trim($bodyCls) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($pageTitle) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#64F0BE">

  <!-- Favicon / brand -->
  <link rel="icon" href="<?= e(asset('assets/favicon.svg')) ?>" type="image/svg+xml">
  <link rel="apple-touch-icon" href="<?= e(asset('assets/favicon.svg')) ?>">

  <!-- Main stylesheet -->
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css?v10')) ?>">

  <?php
  // Allow routes to push extra meta/css into <head>
  if (!empty($head)) echo $head;
  ?>

  <style>
    /* Minimal safety styles for topbar in case styles.css isn’t loaded yet */
    .topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:#111318;border-bottom:1px solid #1f2430}
    .brand{display:inline-flex;align-items:center;gap:10px;text-decoration:none}
    .brand span{font-weight:800;color:#e5e7eb}
    .brand .logo{display:block;width:20px;height:20px;color:#64F0BE}
    .nav a{color:#93c5fd;text-decoration:none;margin-left:14px}
    .container{max-width:980px;margin:0 auto;padding:16px}
    @media (max-width:640px){.container{padding:12px}}
  </style>
</head>
<body class="<?= e($bodyClass) ?>">

<header class="topbar">
  <a class="brand" href="<?= e(asset('')) ?>">
    <!-- Inline logo fallback; you can swap to <img src="<?= e(asset('assets/plugbio-icon.svg')) ?>"> -->
    <svg class="logo" viewBox="0 0 64 64" fill="none">
      <rect x="20" y="6" width="6" height="14" rx="2" fill="currentColor"/>
      <rect x="38" y="6" width="6" height="14" rx="2" fill="currentColor"/>
      <rect x="14" y="18" width="36" height="26" rx="8" stroke="currentColor" stroke-width="4"/>
      <path d="M50 31c8 0 8 12 0 12-9 0-14 8-22 8" stroke="currentColor" stroke-width="4" stroke-linecap="round"/>
      <path d="M20 31h6l3-6 4 14 3-8h8" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <span><?= e(app_name()) ?></span>
  </a>

  <nav class="nav">
    <?php if ($u = current_user()): ?>
      <a href="<?= e(asset('dashboard')) ?>">Dashboard</a>
      <a href="<?= e(asset('pages/new')) ?>">New Page</a>
      <a href="<?= e(asset('logout')) ?>">Logout</a>
    <?php else: ?>
      <a href="<?= e(asset('login')) ?>">Login</a>
      <a href="<?= e(asset('register')) ?>">Register</a>
    <?php endif; ?>
  </nav>
</header>

<main class="container">
  <?= $content ?? '' ?>
</main>

</body>
</html>
