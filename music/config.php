<?php
// Neon (Postgres) configuration
// In Neon dashboard, copy the "psql" details and set the vars below.
// Example host looks like: ep-quiet-sunset-123456.us-east-2.aws.neon.tech

define('PG_HOST', 'ep-shiny-dew-afgf5aor-pooler.c-2.us-west-2.aws.neon.tech');
define('PG_PORT', '5432');
define('PG_DB',   'neondb');
define('PG_USER', 'neondb_owner');
define('PG_PASS', 'npg_bZ05gEMeKwzr');

// Include trailing slash. Example: 'http://localhost/music/'
define('BASE_URL', 'http://localhost/music/');

// File uploads (relative to public/)
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URI', BASE_URL . 'uploads/');

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0775, true);
}

date_default_timezone_set('America/Los_Angeles');
$cookiePath = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?: '/', '/') ?: '/';
session_name('music_landing_sess');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => $cookiePath, // critical when app is in a subfolder (e.g. /music)
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

function route(): string {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '/', '/');
    if ($base && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    return '/' . ltrim($path, '/');
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = PG_HOST;
    // Extract endpoint ID (first label before the first dot)
    $endpoint = explode('.', $host)[0]; // e.g. "ep-silver-owl-123456"

    // Add options=endpoint=<endpoint-id> so Neon can route without SNI
    $dsn = 'pgsql:host=' . $host
         . ';port=' . PG_PORT
         . ';dbname=' . PG_DB
         . ';sslmode=require'
         . ';options=endpoint=' . $endpoint;

    $pdo = new PDO($dsn, PG_USER, PG_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}


function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? null)) {
            http_response_code(422);
            exit('Invalid CSRF token');
        }
    }
}

function current_user() {
    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('SELECT id, email, username FROM users WHERE id = :id');
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }
    return null;
}

function require_auth() {
    if (!current_user()) {
        header('Location: ' . BASE_URL . 'login');
        exit;
    }
}

function time_ago(string $ts): string {
    $dt = new DateTime($ts);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $dt->getTimestamp();

    if ($diff < 60) return $diff.'s ago';
    if ($diff < 3600) return floor($diff/60).'m ago';
    if ($diff < 86400) return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return $dt->format('M j, Y');
}

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function slugify($text) {
  $text = preg_replace('~[^\pL\d]+~u', '-', $text);
  $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
  $text = preg_replace('~[^-\w]+~', '', $text);
  $text = trim($text, '-');
  $text = preg_replace('~-+~', '-', $text);
  $text = strtolower($text);
  return $text ?: 'page';
}

function parse_links_to_json(string $raw): string {
    $lines = preg_split('/\R+/', trim($raw));
    $links = [];
    $id = 1;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Allow "Label | URL" or just "URL"
        $label = null; $url = $line;
        if (strpos($line, '|') !== false) {
            [$maybeLabel, $maybeUrl] = array_map('trim', explode('|', $line, 2));
            if (filter_var($maybeUrl, FILTER_VALIDATE_URL)) {
                $label = $maybeLabel;
                $url = $maybeUrl;
            }
        }

        // Validate URL only
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

        // Auto-label by host if no label provided
        if ($label === null) {
            $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
            $label = match (true) {
                str_contains($host, 'open.spotify.')     => 'Spotify',
                str_contains($host, 'music.apple.')      => 'Apple Music',
                str_contains($host, 'itunes.apple.')     => 'Apple Music',
                str_contains($host, 'youtube.')          => 'YouTube',
                str_contains($host, 'youtu.be')          => 'YouTube',
                str_contains($host, 'soundcloud.')       => 'SoundCloud',
                str_contains($host, 'tidal.')            => 'TIDAL',
                str_contains($host, 'deezer.')           => 'Deezer',
                str_contains($host, 'pandora.')          => 'Pandora',
                str_contains($host, 'music.amazon.')     => 'Amazon Music',
                str_contains($host, 'bandcamp.')         => 'Bandcamp',
                default => ucfirst(preg_replace('/^www\\./', '', explode('.', $host)[0] ?? 'Link')),
            };
        }

        $links[] = ['id' => $id++, 'label' => $label, 'url' => $url];
    }

    return json_encode($links, JSON_UNESCAPED_SLASHES);
}
