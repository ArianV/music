<?php
// views/layout.php
require_once __DIR__ . '/../config.php';
$base_url  = rtrim(getenv('BASE_URL') ?: 'https://www.plugb.ink', '/');

if (!function_exists('meta_get')) {
  // Fallback if helpers weren’t included yet (won’t overwrite if defined in config.php)
  $GLOBALS['__meta'] = $GLOBALS['__meta'] ?? [];
  function meta_get(string $k, string $default = ''): string {
    return $GLOBALS['__meta'][$k] ?? $default;
  }
}

$me = function_exists('current_user') ? (current_user() ?? null) : null;

$brand   = 'PlugBio';
$title   = $title   ?? $brand;
$head    = $head    ?? '';
$content = $content ?? '';
$desc  = meta_get('description', 'Create beautiful link pages for your music.');
$image = meta_get('image', $base_url.'/favicon-512.png');
$url   = meta_get('url', $base_url.($_SERVER['REQUEST_URI'] ?? '/'));

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
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css')) ?>">

  <meta name="description" content="<?= e($desc) ?>">

  <!-- Open Graph -->
  <meta property="og:site_name" content="<?= e($site_name) ?>">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url" content="<?= e($url) ?>">
  <meta property="og:type" content="website">
  <meta property="og:image" content="<?= e($image) ?>">
  <meta property="og:image:alt" content="<?= e($title) ?>">

  <!-- Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($title) ?>">
  <meta name="twitter:description" content="<?= e($desc) ?>">
  <meta name="twitter:image" content="<?= e($image) ?>">

  <!-- Favicons -->
  <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/favicon.svg')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset('assets/favicon-32.png')) ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= e(asset('assets/favicon-16.png')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset('assets/apple-touch-icon.png')) ?>">
  <link rel="mask-icon" href="<?= e(asset('assets/safari-pinned-tab.svg')) ?>" color="#22c55e">
  <meta name="theme-color" content="#0b0b0c">

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
    .nav-user{position:relative;padding: 5px 6px 0px 16px;}
    .btn{display:inline-flex;align-items:center;gap:8px;background:#1f2937;color:#e5e7eb;border:1px solid #2a3344;border-radius:10px;padding:8px 12px;text-decoration:none}
    .btn:hover{background:#232e44}
    .page{max-width:980px;margin:24px auto;padding:0 16px}

    /* === ambient background (prototype) === */
.fx-ambient{position:fixed; inset:-12% -12% -12% -12%; z-index:0; pointer-events:none;}
.fx-ambient .blob{
  position:absolute; width:48vw; height:48vw; border-radius:999px;
  filter: blur(60px); opacity:.12; mix-blend-mode:screen;
  background: radial-gradient(35% 35% at 50% 50%, currentColor 0%, transparent 60%);
}
/* brand-ish hues */
.fx-ambient .b1{ color:#22c55e; left:8%;  top:0%;   animation:float1 28s ease-in-out infinite; }
.fx-ambient .b2{ color:#60a5fa; right:6%; top:10%;  animation:float2 36s ease-in-out infinite; }
.fx-ambient .b3{ color:#a855f7; left:40%; bottom:0; animation:float3 42s ease-in-out infinite; }

/* very subtle grain to make it feel alive */
.fx-ambient .grain{
  position:absolute; inset:-50%;
  background-image:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='140' height='140'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix type='saturate' values='0'/><feComponentTransfer><feFuncA type='table' tableValues='0 0 .9 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0'/></feComponentTransfer></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>");
  opacity:.035; mix-blend-mode:soft-light; animation:grain 9s steps(10) infinite;
}
/* keep motion tasteful */
@keyframes float1 { 0%{transform:translate3d(0,0,0)} 50%{transform:translate3d(7vw,6vh,0) scale(1.05)} 100%{transform:translate3d(0,0,0)} }
@keyframes float2 { 0%{transform:translate3d(0,0,0)} 50%{transform:translate3d(-6vw,5vh,0) scale(1.06)} 100%{transform:translate3d(0,0,0)} }
@keyframes float3 { 0%{transform:translate3d(0,0,0)} 50%{transform:translate3d(4vw,-6vh,0) scale(1.04)} 100%{transform:translate3d(0,0,0)} }
@keyframes grain  { 0%{transform:translate(0,0)} 25%{transform:translate(-1%,1%)} 50%{transform:translate(1%,-1%)} 75%{transform:translate(-1%,-1%)} 100%{transform:translate(0,0)} }

  /* Centered page container (same look as before) */
  .wrap{
    max-width: 1100px;
    margin: 24px auto;
    padding: 0 16px;
  }

/* ensure your main content sits above */
.site-main{position:relative; z-index:1}

/* accessibility */
@media (prefers-reduced-motion: reduce){
  .fx-ambient .blob,.fx-ambient .grain{animation:none}
}
  </style>
  <?= $head ?>
</head>
<body>
  <header class="nav">
    <a class="brand" href="<?= e(asset($me ? 'feed' : '')) ?>">
      <!-- Inline PlugBio mark (teal) -->
      <svg class="logo" viewBox="0 0 24 24" aria-hidden="true">
        <!-- simple “plug” mark with a subtle face notch; uses currentColor (teal via CSS) -->
        <path fill="currentColor" d="M8 2h2v4h4V2h2v4h1.5A2.5 2.5 0 0 1 20 8.5V12a8 8 0 0 1-6 7.75V22h-4v-2.25A8 8 0 0 1 4 12V8.5A2.5 2.5 0 0 1 6.5 6H8V2Zm-1 8.5a.75.75 0 0 0 1.5 0 .75.75 0 0 0-1.5 0Zm8 0a.75.75 0 0 0 1.5 0 .75.75 0 0 0-1.5 0Z"/>
      </svg>
      <span>PlugBio</span>
    </a>

    <div class="nav-right">
      <?php if ($me): ?>
        <a class="link" href="<?= e(asset('feed')) ?>">Feed</a>
        <a class="link" href="<?= e(asset('dashboard')) ?>">Dashboard</a>
        <a class="link" href="<?= e(asset('analytics')) ?>" style="color: #0087ff;">Creator Tools</a>
        <a class="link" href="<?= e(asset('/pages/new')) ?>">New Page</a>

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
            <a href="<?= e(asset('settings')) ?>">Settings</a>
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


  <?php if ($u = current_user()): ?>
    <?php if (!is_verified($u)): ?>
      <div style="background:#3a2a06;border:1px solid #a16207;color:#fde68a;padding:10px 14px;margin:10px auto;border-radius:10px;max-width:1100px">
        Please verify your email to unlock all features.
        <form method="post" action="<?= e(asset('verify-resend')) ?>" style="display:inline;margin-left:10px">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <button class="btn secondary" style="height:28px;padding:0 10px;font-size:12px">Resend link</button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>


  <?php if (getenv('FX_BG') === '1'): ?>
    <div class="fx-ambient" aria-hidden="true">
      <div class="blob b1"></div>
      <div class="blob b2"></div>
      <div class="blob b3"></div>
      <div class="grain"></div>
    </div>
  <?php endif; ?>
  
  <?php
    // allow pages to opt-out of the wrapper if they really need full-bleed
    $full_bleed = $full_bleed ?? false;
  ?>
  <main class="site-main">
    <?php if (!$full_bleed): ?><div class="wrap"><?php endif; ?>
      <?= $content ?>
    <?php if (!$full_bleed): ?></div><?php endif; ?>
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
