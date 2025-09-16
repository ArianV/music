<?php
// ====== pages_delete.php ======
require_once __DIR__ . '/config.php';
require_auth();
csrf_check();

$user = current_user();
if (!$user) { header('Location: ' . BASE_URL . 'login'); exit; }

// Accept id via URL: /pages/{id}/delete or ?id=...
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$key  = null;
if (preg_match('#^/pages/([^/]+)/delete$#', $path, $m)) {
  $key = rawurldecode($m[1]);
} elseif (isset($_GET['id'])) {
  $key = $_GET['id'];
}
if ($key === null || $key === '') { http_response_code(404); exit('Not found'); }

$pdo = db();

// Load row scoped to owner
if (ctype_digit((string)$key)) {
  $st = $pdo->prepare("SELECT * FROM pages WHERE id = :id AND user_id = :uid");
  $st->execute([':id' => (int)$key, ':uid' => $user['id']]);
} else {
  $st = $pdo->prepare("SELECT * FROM pages WHERE slug = :slug AND user_id = :uid");
  $st->execute([':slug' => $key, ':uid' => $user['id']]);
}
$page = $st->fetch(PDO::FETCH_ASSOC);
if (!$page) { http_response_code(404); exit('Not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $del = $pdo->prepare("DELETE FROM pages WHERE id = :id AND user_id = :uid");
  $del->execute([':id' => (int)$page['id'], ':uid' => $user['id']]);

  header('Location: ' . BASE_URL . 'dashboard');
  exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Delete page</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <base href="<?= e(BASE_URL) ?>">
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;margin:0;background:#0b0b0c;color:#e5e7eb}
    .wrap{max-width:700px;margin:40px auto;padding:0 16px}
    .panel{background:#111318;border:1px solid #1f2430;border-radius:12px;padding:20px}
    .row{margin-bottom:14px}
    .btn{display:inline-block;background:#dc2626;color:#fff;border:none;border-radius:8px;padding:10px 14px;cursor:pointer}
    .link{color:#93c5fd;text-decoration:none;margin-left:10px}
  </style>
</head>
<body>
<div class="wrap">
  <div class="panel">
    <h1>Delete “<?= e($page['title'] ?? 'Untitled') ?>”</h1>
    <p>This action cannot be undone.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button type="submit" class="btn">Delete</button>
      <a class="link" href="<?= e(BASE_URL . 'dashboard') ?>">Cancel</a>
    </form>
  </div>
</div>
</body>
</html>
