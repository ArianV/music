<?php
require __DIR__ . '/config.php';
csrf_check();
error_log('ROUTE path=' . route());

$path = route();
$user = current_user();

// Routing table
if ($path === '/' || $path === '/home') {
    require __DIR__ . '/routes/home.php';
    exit;
}

if ($path === '/login') { require __DIR__ . '/routes/login.php'; exit; }
if ($path === '/register') { require __DIR__ . '/routes/register.php'; exit; }
if ($path === '/logout') { require __DIR__ . '/routes/logout.php'; exit; }

if ($path === '/dashboard') { require_auth(); require __DIR__ . '/routes/dashboard.php'; exit; }
if ($path === '/pages/new') { require_auth(); require __DIR__ . '/routes/pages_new.php'; exit; }

// Edit page
if (preg_match('#^/pages/([a-z0-9-]+)/edit$#', $path, $m)) {
    require_auth();
    $_GET['slug'] = $m[1];
    require __DIR__ . '/routes/pages_edit.php';
    exit;
}

// Delete page
if (preg_match('#^/pages/([a-z0-9-]+)/delete$#i', $path, $m)) {
    require_auth();
    $_GET['slug'] = $m[1];
    require __DIR__ . '/routes/pages_delete.php';
    exit;
}

// Redirect for link clicks
if (preg_match('#^/r/(\d+)$#', $path, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/routes/redirect.php';
    exit;
}

// Public profile pages: /@username/<slug>
if (preg_match('#^/@([a-z0-9_]+)/([a-z0-9-]+)$#i', $path, $m)) {
    $_GET['username'] = $m[1];
    $_GET['slug'] = $m[2];
    require __DIR__ . '/routes/page_public.php';
    exit;
}

// 404
http_response_code(404);
echo "<h1>Not Found</h1>";
