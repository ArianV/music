<?php
// ====== config.php (fixed drop-in) ======
// Core config, DB, auth, helpers, and router

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ---------- Base URL ----------
if (!defined('BASE_URL')) {
  $envBase = getenv('BASE_URL'); // prefer env if provided
  if ($envBase) {
    // normalize: ensure trailing slash
    $envBase = rtrim($envBase, '/') . '/';
    define('BASE_URL', $envBase);
  } else {
    // infer from request
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $base   = $base === '' ? '/' : $base . '/';
    define('BASE_URL', $scheme . '://' . $host . $base);
  }
}


// ---------- HTML escape ----------
if (!function_exists('e')) {
  function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// ---------- Database (PDO Postgres) ----------
// ---------- Database (PDO Postgres) ----------
if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    // 1) Heroku/Railway style DATABASE_URL takes priority
    $dsnUrl = getenv('DATABASE_URL') ?: getenv('PGURL') ?: '';

    if ($dsnUrl && str_starts_with($dsnUrl, 'postgres')) {
      $parts = parse_url($dsnUrl);
      $host   = $parts['host'] ?? 'localhost';
      $port   = $parts['port'] ?? 5432;
      $dbname = ltrim($parts['path'] ?? '/postgres', '/');
      $user   = $parts['user'] ?? '';
      $pass   = $parts['pass'] ?? '';
      $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
      return $pdo;
    }

    // 2) Support BOTH standard libpq names and your PG_* names
    $host = getenv('PGHOST')      ?: getenv('PG_HOST') ?: getenv('DB_HOST') ?: 'localhost';
    $port = getenv('PGPORT')      ?: getenv('PG_PORT') ?: getenv('DB_PORT') ?: '5432';
    $name = getenv('PGDATABASE')  ?: getenv('PG_DB')   ?: getenv('DB_NAME') ?: 'postgres';
    $user = getenv('PGUSER')      ?: getenv('PG_USER') ?: getenv('DB_USER') ?: 'postgres';
    $pass = getenv('PGPASSWORD')  ?: getenv('PG_PASS') ?: getenv('DB_PASS') ?: '';

    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
  }
}


// ---------- CSRF ----------
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
      $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
  }
}
if (!function_exists('csrf_check')) {
  function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
      if (!$ok) { http_response_code(400); exit('Invalid CSRF token'); }
    }
  }
}

// ---------- Auth helpers ----------
if (!function_exists('current_user')) {
  function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $st = db()->prepare('SELECT * FROM users WHERE id = :id');
    $st->execute([':id' => (int)$_SESSION['user_id']]);
    $u = $st->fetch();
    return $u ?: null;
  }
}
if (!function_exists('require_auth')) {
  function require_auth(): void {
    if (!current_user()) {
      header('Location: ' . BASE_URL . 'login');
      exit;
    }
  }
}

// ---------- Uploads (cover images) ----------
if (!defined('UPLOAD_DIR')) {
  define('UPLOAD_DIR', __DIR__ . '/uploads');
}
if (!defined('UPLOAD_URI')) {
  define('UPLOAD_URI', rtrim(BASE_URL, '/') . '/uploads');
}
if (!is_dir(UPLOAD_DIR)) {
  @mkdir(UPLOAD_DIR, 0775, true);
}

// ---------- Misc helpers ----------
if (!function_exists('page_cover')) {
  function page_cover(array $row): ?string {
    return $row['cover_uri'] ?? $row['cover_url'] ?? $row['cover_image'] ?? null;
  }
}
if (!function_exists('slugify')) {
  function slugify(string $s): string {
    $s = strtolower(preg_replace('/[^a-z0-9]+/', '-', $s));
    return trim($s, '-');
  }
}
if (!function_exists('table_has_column')) {
  function table_has_column(PDO $pdo, string $table, string $col): bool {
    static $cache = [];
    $key = strtolower($table);
    if (!isset($cache[$key])) {
      $st = db()->prepare("
        SELECT lower(column_name) AS c
        FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = :t
      ");
      $st->execute([':t' => $table]);
      $cache[$key] = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'c');
    }
    return in_array(strtolower($col), $cache[$key], true);
  }
}
if (!function_exists('log_msg')) {
  function log_msg(string $msg): void {
    error_log($msg);
  }
}

// ---------- Router ----------
if (!isset($GLOBALS['__ROUTES'])) {
  $GLOBALS['__ROUTES'] = ['exact' => [], 'regex' => []];
}

if (!function_exists('route')) {
  function route(?string $path = null, ?string $file = null): void {
    if ($path !== null && $file !== null) {
      $GLOBALS['__ROUTES']['exact'][] = ['path' => $path, 'file' => $file];
      return;
    }
    route_dispatch();
  }
}

if (!function_exists('route_regex')) {
  function route_regex(string $pattern, string $file, array $varsMap = []): void {
    $GLOBALS['__ROUTES']['regex'][] = ['pattern' => $pattern, 'file' => $file, 'vars' => $varsMap];
  }
}

if (!function_exists('route_dispatch')) {
  function route_dispatch(): void {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

    foreach ($GLOBALS['__ROUTES']['exact'] as $r) {
      if ($uri === $r['path']) {
        require __DIR__ . '/' . ltrim($r['file'], '/');
        exit;
      }
    }
    foreach ($GLOBALS['__ROUTES']['regex'] as $r) {
      if (preg_match($r['pattern'], $uri, $m)) {
        foreach ($r['vars'] as $name => $idx) {
          $GLOBALS[$name] = $m[$idx] ?? null;
        }
        require __DIR__ . '/' . ltrim($r['file'], '/');
        exit;
      }
    }
    http_response_code(404);
    echo 'Not Found';
    exit;
  }
}
