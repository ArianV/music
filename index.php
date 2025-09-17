<?php
// Serve static files directly if your server rewrites everything to index.php
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (preg_match('#^/(assets|uploads)/#', $uri)) {
  $path = __DIR__ . $uri;
  if (is_file($path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $ct  = [
      'css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg',
      'jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml','gif'=>'image/gif'
    ][$ext] ?? 'application/octet-stream';
    header('Content-Type: '.$ct);
    readfile($path);
    exit;
  }
}

require_once __DIR__ . '/config.php';

// ----- exact routes -----
route('/',              'routes/dashboard.php');   // or routes/home.php if you prefer
route('/login',         'routes/login.php');
route('/register',      'routes/register.php');    // <— add this
route('/logout',        'routes/logout.php');
route('/dashboard',     'routes/dashboard.php');
route('/pages/new',     'routes/pages_new.php');

// --- NEW: public pages like /@foaf/lil-uzi-vert-20-min ---
route_regex('#^/@([^/]+)/([^/]+)/?$#', 'routes/page_public.php', ['handle' => 1, 'page_key' => 2]);
// Short public URL: /s/{slug}
route_regex('#^/s/([^/]+)/?$#', 'routes/page_public.php', ['page_key' => 1]);
// Social preview image (1200×630 PNG): /og/{slug-or-id}
route_regex('#^/og/([^/]+)/?$#', 'routes/og.php', ['key' => 1]);


// ----- regex routes -----
route_regex('#^/pages/([^/]+)/edit$#',   'routes/pages_edit.php',   ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/delete$#', 'routes/pages_delete.php', ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/?$#',      'routes/page_public.php',  ['page_key' => 1]);

// dispatch
route();
