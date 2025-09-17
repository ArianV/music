<?php
// views/layout.php
require_once __DIR__ . '/../config.php';
$me = current_user();

$brand   = 'PlugBio';
$title   = $title   ?? $brand;
$head    = $head    ?? '';
$content = $content ?? '';

$handle = $me['handle'] ?? '';
$avatar = $me['avatar_uri'] ?? '';
$myProfileUrl = $handle ? asset('u/' . $handle) : asset('profile');

// simple avatar fallback (1st letter)
$initial = strtoupper(substr(trim($me['display_name'] ?? $handle ?? 'U'), 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css?v4')) ?>">
  <style>
    /* Navbar avatar menu */
    .nav{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #1f2430;background:#0b0b0c;position:sticky;top:0;z-index:50}
    .brand{color:#7ef1c7;font-weight:600;text-decoration:none}
    .nav a.link{color:#bcd;color:#c7d2fe;text-decoration:none;margin-left:14px}
    .nav a.link:hover{opacity:.9}
    .nav-right{display:flex;align-items:center;gap:10px}
    .avatar-btn{border:none;background:transparent;padding:0;cursor:pointer;border-radius:999px;outline-offset:2px}
    .avatar-btn:focus-visible{outline:2px solid #3b82f6}
    .avatar-img{width:32px;height:32px;border-radius:999px;object-fit:cover;border:1px solid #263142;background:#0f1217;color:#9aa;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600}
    .menu{position:absolute;right:12px;top:54px;background:#0f1217;border:1px solid #263142;border-radius:12px;min-width:200px;box-shadow:0 12px 30px rgba(0,0,0,.4);padding:6px}
    .menu a{display:flex;align-items:center;gap:8px;color:#e5e7eb;text-decoration:none;padding:10px 12px;border-radius:10px}
    .menu a:hover{background:#151925}
    .menu hr{border:0;border-top:1px solid #1f2430;margin:6px}
    .nav-user{position:relative}
    .btn{display:inline-flex;align-items:center;gap:8px;background:#1f2937;color:#e5e7eb;border:1px solid #2a3344;border-radius:10px;padding:8px 12px;text-decoration:none}
    .btn:hover{background:#232e44}
    .page{max-width:980px;margin:24px auto;padding:0 16px}
  </style>
  <?= $head ?>
</head>
<body>
  <header class="nav">
    <a class="brand" href="<?= e(asset($me ? 'dashboard' : '')) ?>"><?= e($brand) ?></a>

    <div class="nav-right">
      <?php if ($me): ?>
        <a class="link" href="<?= e(asset('dashboard')) ?>">Dashboard</a>
        <a class="link" href="<?= e(asset('pages/new')) ?>">New Page</a>

        <div class="nav-user">
          <button class="avatar-btn" id="navAvatarBtn" aria-haspopup="menu" aria-expanded="false" aria-label="Open user menu">
            <?php if ($avatar): ?>
              <img class="avatar-img" src="<?= e($avatar) ?>" alt="Me">
            <?php else: ?>
              <div class="avatar-img" aria-hidden="true"><?= e($initial) ?></div>
            <?php endif; ?>
          </button>
          <div class="menu" id="navMenu" hidden>
            <a href="<?= e($myProfileUrl) ?>">My profile</a>
            <a href="<?= e(asset('profile')) ?>">Settings</a>
            <hr>
            <a href="<?= e(asset('logout')) ?>">Logout</a>
          </div>
        </div>
      <?php else: ?>
        <a class="link" href="<?= e(asset('login')) ?>">Login</a>
        <a class="link" href <?= '="'.e(asset('register')).'"' ?>>Register</a>
      <?php endif; ?>
    </div>
  </header>

  <main class="page">
    <?= $content ?>
  </main>

  <script>
  (function(){
    const btn = document.getElementById('navAvatarBtn');
    const menu = document.getElementById('navMenu');
    if (!btn || !menu) return;
    const open = (val) => { menu.hidden = !val; btn.setAttribute('aria-expanded', String(val)); };
    btn.addEventListener('click', (e)=>{ e.stopPropagation(); open(menu.hidden); });
    document.addEventListener('click', (e)=>{
      if (!menu.hidden && !menu.contains(e.target)) open(false);
    });
    document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') open(false); });
  })();
  </script>
</body>
</html>
