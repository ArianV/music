<?php
// routes/logout.php — POST only (with CSRF) so other sites can't log people out
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . asset('')); exit; }
csrf_check();

$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', [
  'expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'],
  'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'],
]);
session_destroy();

header('Location: ' . asset(''));
exit;
