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
    .brand{display:inline-flex;align-items:center;gap:8px;font-weight:700;text-decoration:none;color:#e5e7eb;}
    .brand .logo{width:22px;height:22px;display:block;color:#7ef1c7}
    .brand:hover{opacity:.9}
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
    <a class="brand" href="<?= e(asset($me ? 'dashboard' : '')) ?>">
      <!-- Inline PlugBio mark (teal) -->
      <svg class="logo" viewBox="0 0 24 24" aria-hidden="true">
        <!-- simple “plug” mark with a subtle face notch; uses currentColor (teal via CSS) -->
        <path fill="currentColor" d="M8 2h2v4h4V2h2v4h1.5A2.5 2.5 0 0 1 20 8.5V12a8 8 0 0 1-6 7.75V22h-4v-2.25A8 8 0 0 1 4 12V8.5A2.5 2.5 0 0 1 6.5 6H8V2Zm-1 8.5a.75.75 0 0 0 1.5 0 .75.75 0 0 0-1.5 0Zm8 0a.75.75 0 0 0 1.5 0 .75.75 0 0 0-1.5 0Z"/>
      </svg>
      <span>PlugBio</span>
    </a>

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
