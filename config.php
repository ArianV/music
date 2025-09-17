<?php
// ====== config.php (clean drop-in) ======
// One place for config, DB, helpers, and router.

if (session_status() === PHP_SESSION_NONE) {
  // Safer cookie defaults
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
  $cookiePath = '/';
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $cookiePath,
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => $scheme === 'https',
  ]);
  session_start();
}

// ---------- BASE_URL ----------
if (!defined('BASE_URL')) {
  $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http' );
  $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
  $base  = $base === '' ? '/' : $base . '/';
  define('BASE_URL', $proto . '://' . $host . $base);
}

// Small HTML escape
if (!function_exists('e')) {
  function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// Public URL builder for assets/paths
if (!function_exists('asset')) {
  function asset(string $path=''): string {
    $path = ltrim($path, '/');
    return rtrim(BASE_URL, '/') . '/' . $path;
  }
}

// ---------- Database (Postgres via PDO) ----------
if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $opts = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => true,   // avoid cached-plan issues after ALTER TABLE
      PDO::ATTR_STRINGIFY_FETCHES  => false,
    ];

    // Prefer a DATABASE_URL (Neon/Railway style)
    $url = getenv('DATABASE_URL') ?: getenv('PGURL') ?: '';
    if ($url && (str_starts_with($url, 'postgres://') || str_starts_with($url, 'postgresql://'))) {
      $parts  = parse_url($url);
      $host   = $parts['host'] ?? 'localhost';
      $port   = (string)($parts['port'] ?? 5432);
      $dbname = ltrim($parts['path'] ?? '/postgres', '/');
      $user   = $parts['user'] ?? '';
      $pass   = $parts['pass'] ?? '';

      // Ensure sslmode=require for Neon unless already given
      $query = [];
      if (!empty($parts['query'])) parse_str($parts['query'], $query);
      $sslmode = $query['sslmode'] ?? (getenv('DB_SSLMODE') ?: 'require');

      $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
      $pdo = new PDO($dsn, $user, $pass, $opts);
      return $pdo;
    }

    // Fallback to discrete env vars
    $host   = getenv('DB_HOST') ?: getenv('PG_HOST') ?: 'localhost';
    $port   = (string)(getenv('DB_PORT') ?: getenv('PG_PORT') ?: '5432');
    $dbname = getenv('DB_NAME') ?: getenv('PG_DB') ?: getenv('PGDATABASE') ?: 'postgres';
    $user   = getenv('DB_USER') ?: getenv('PG_USER') ?: getenv('PGUSER') ?: 'postgres';
    $pass   = getenv('DB_PASS') ?: getenv('PG_PASS') ?: getenv('PGPASSWORD') ?: '';
    $ssl    = getenv('DB_SSLMODE') ?: '';

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}" . ($ssl ? ";sslmode={$ssl}" : '');
    $pdo = new PDO($dsn, $user, $pass, $opts);
    return $pdo;
  }
}

// ---------- CSRF ----------
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
  }
}
if (!function_exists('csrf_check')) {
  function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
      if (!$ok) { http_response_code(422); exit('Invalid CSRF token'); }
    }
  }
}

// ---------- Auth ----------
if (!function_exists('current_user')) {
  function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id=:id LIMIT 1');
    $st->execute([':id' => (int)$_SESSION['user_id']]);
    $u = $st->fetch();
    return $u ?: null;
  }
}
if (!function_exists('require_auth')) {
  function require_auth(): void {
    if (!current_user()) { header('Location: ' . asset('login')); exit; }
  }
}

// Uploads 
$uploadDirEnv = getenv('UPLOAD_DIR');
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', $uploadDirEnv ?: (__DIR__ . '/uploads'));
if (!defined('UPLOAD_URI')) define('UPLOAD_URI', rtrim(BASE_URL, '/') . '/uploads');
@mkdir(UPLOAD_DIR, 0777, true);
@chmod(UPLOAD_DIR, 0777);


// try to self-heal the directory so move_uploaded_file works
if (!is_dir(UPLOAD_DIR)) {
  @mkdir(UPLOAD_DIR, 0775, true);
}
if (!is_writable(UPLOAD_DIR)) {
  // attempt to relax perms; chown may not be allowed on your host, so we do chmod
  @chmod(UPLOAD_DIR, 0777); // last resort: wide-open so it always works
}

// ---------- Helpers ----------
if (!function_exists('page_cover')) {
  function page_cover(array $row): ?string {
    return $row['cover_uri'] ?? $row['cover_url'] ?? $row['cover_image'] ?? null;
  }
}
if (!function_exists('slugify')) {
  function slugify(string $s, int $maxLen=80): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
    if (function_exists('iconv')) { $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if ($t!==false) $s=$t; }
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    $s = trim($s, '-');
    if ($maxLen>0 && strlen($s)>$maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
    return $s ?: 'page';
  }
}
if (!function_exists('time_ago')) {
  function time_ago($ts): string {
    if (!$ts) return '';
    $t = is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts);
    $d = max(0, time() - $t);
    foreach (['year'=>31536000,'month'=>2592000,'week'=>604800,'day'=>86400,'hour'=>3600,'min'=>60] as $name=>$secs) {
      if ($d >= $secs) { $n = (int)floor($d/$secs); return "$n {$name}".($n>1?'s':'')." ago"; }
    }
    return 'just now';
  }
}
if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = strtolower($table);
    if (!isset($cache[$key])) {
      $st = $pdo->prepare("
        SELECT lower(column_name) AS c
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name   = :t
      ");
      $st->execute([':t' => $table]);
      $cache[$key] = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'c');
    }
    return in_array(strtolower($col), $cache[$key], true);
  }
}
if (!function_exists('log_msg')) {
  function log_msg(string $msg): void { error_log($msg); }
}

// ---------- Router ----------
$GLOBALS['__ROUTES'] = $GLOBALS['__ROUTES'] ?? [];
$GLOBALS['__RREG']   = $GLOBALS['__RREG']   ?? [];

if (!function_exists('route')) {
  function route(?string $path=null, ?string $file=null) {
    if ($path === null) return route_dispatch(); // dispatch now
    $GLOBALS['__ROUTES'][$path] = $file;
  }
}
if (!function_exists('route_regex')) {
  function route_regex(string $pattern, string $file, array $groups=[]): void {
    $GLOBALS['__RREG'][] = [$pattern, $file, $groups];
  }
}
if (!function_exists('route_dispatch')) {
  function route_dispatch(): void {
    $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $path = rtrim($uri, '/') ?: '/';

    // exact match
    if (isset($GLOBALS['__ROUTES'][$path])) {
      require __DIR__ . '/' . ltrim($GLOBALS['__ROUTES'][$path], '/');
      return;
    }

    // regex match
    foreach ($GLOBALS['__RREG'] as [$pat, $file, $groups]) {
      if (preg_match($pat, $path, $m)) {
        foreach ($groups as $name => $idx) $GLOBALS[$name] = $m[$idx] ?? null;
        require __DIR__ . '/' . ltrim($file, '/');
        return;
      }
    }

    // fallback 404
    http_response_code(404);
    if (is_file(__DIR__ . '/routes/404.php')) {
      require __DIR__ . '/routes/404.php';
    } else {
      exit('Not found');
    }
  }
}
