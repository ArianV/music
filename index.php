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

// ---- exact routes
route('/', 'routes/dashboard.php');
route('/dashboard', 'routes/dashboard.php');
route('/login', 'routes/login.php');
route('/register', 'routes/register.php');
route('/logout', 'routes/logout.php');
route('/pages/new', 'routes/pages_new.php');
route('/profile', 'routes/profile_edit.php'); 
route('/settings', 'routes/account_settings.php');

// Diagnostic Pages
route('/diag/upload', 'routes/diag_upload.php');
route('/diag/persist', 'routes/diag_persist.php');
route('/health', 'routes/health.php');
route('/email-test', 'routes/email_test.php');

// Email senders
route('/verify-email',   'routes/verify_email.php');
route('/verify-resend',  'routes/verify_resend.php');

// ---- pages (slugs or numeric ids) — SPECIFIC FIRST
route_regex('#^/pages/([^/]+)/edit/?$#', 'routes/pages_edit.php',   ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/delete/?$#', 'routes/pages_delete.php', ['page_id' => 1]);

// Public page URLs
route_regex('#^/s/([^/]+)/?$#', 'routes/page_public.php', ['page_key' => 1]);
// (optional) keep @handle/slug if you still use it:
route_regex('#^/@([^/]+)/([^/]+)/?$#', 'routes/page_public.php', ['handle' => 1, 'page_key' => 2]);

// Public profile
route_regex('#^/u/([^/]+)/?$#', 'routes/profile_view.php', ['handle' => 1]);

route();