<?php
// ====== config.php (drop-in) ======
// Safe, single-place definitions and helpers for your app.
// Works on Railway/Neon. Uses env vars if present.

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// ---------- Base URL ----------
if (!defined('BASE_URL')) {
  // Try to infer BASE_URL if not defined elsewhere
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $base   = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
  $base   = $base === '' ? '/' : $base . '/';
  define('BASE_URL', $scheme . '://' . $host . $base);
}

// ---------- HTML escape ----------
if (!function_exists('e')) {
  function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}

// ---------- Database (PDO Postgres) ----------
if (!function_exists('db')) {
  function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    // Railway/Neon typical envs
    $dsn  = getenv('DATABASE_URL') ?: getenv('PGURL') ?: '';
    $user = getenv('DB_USER') ?: getenv('PGUSER') ?: '';
    $pass = getenv('DB_PASS') ?: getenv('PGPASSWORD') ?: '';

    if ($dsn && str_starts_with($dsn, 'postgres')) {
      // DATABASE_URL form: postgres://user:pass@host:port/dbname
      // PDO needs:        pgsql:host=...;port=...;dbname=...;user=...;password=...
      $parts = parse_url($dsn);
      $host  = $parts['host'] ?? 'localhost';
      $port  = $parts['port'] ?? 5432;
      $dbname= ltrim($parts['path'] ?? '/postgres', '/');
      $user  = $parts['user'] ?? $user;
      $pass  = $parts['pass'] ?? $pass;
      $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } else {
      // Fallback: explicit envs
      $host  = getenv('DB_HOST') ?: 'localhost';
      $port  = getenv('DB_PORT') ?: '5432';
      $name  = getenv('DB_NAME') ?: 'postgres';
      $user  = $user ?: 'postgres';
      $pass  = $pass ?: '';
      $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    }
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
  // Put uploads inside your public web root
  define('UPLOAD_DIR', __DIR__ . '/uploads');
}
if (!defined('UPLOAD_URI')) {
  define('UPLOAD_URI', rtrim(BASE_URL, '/') . '/uploads');
}
if (!is_dir(UPLOAD_DIR)) {
  @mkdir(UPLOAD_DIR, 0775, true);
}

// ---------- Reusable helpers ----------
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

// ---------- Error logging shortcut ----------
if (!function_exists('log_msg')) {
  function log_msg(string $msg): void {
    error_log($msg);
  }
}

if (!function_exists('route')) {
  function route(string $path, string $file): void {
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($uri === $path) {
      require __DIR__ . '/' . ltrim($file, '/');
      exit;
    }
  }
}

