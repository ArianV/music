<?php
define('PG_HOST', getenv('PG_HOST') ?: '');
define('PG_PORT', getenv('PG_PORT') ?: '5432');
define('PG_DB',   getenv('PG_DB')   ?: '');
define('PG_USER', getenv('PG_USER') ?: '');
define('PG_PASS', getenv('PG_PASS') ?: '');
define('BASE_URL', rtrim(getenv('BASE_URL') ?: '/', '/') . '/');

$cookiePath = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?: '/', '/') ?: '/';
session_name('music_landing_sess');
session_set_cookie_params(['lifetime'=>0,'path'=>$cookiePath,'httponly'=>true,'samesite'=>'Lax']);
session_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/php-error.log');

function route(): string {
  $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  $base = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '/', '/');
  if ($base && str_starts_with($path, $base)) $path = substr($path, strlen($base));
  return '/' . ltrim($path, '/');
}

function db(): PDO {
  static $pdo; if ($pdo) return $pdo;
  $host = PG_HOST;
  $endpoint = null;
  if (preg_match('/^(ep-[a-z0-9-]+)/i', $host, $m)) $endpoint = $m[1];

  $dsn = 'pgsql:host=' . $host
       . ';port=' . PG_PORT
       . ';dbname=' . PG_DB
       . ';sslmode=require'
       . ($endpoint ? ';options=endpoint=' . $endpoint : '');

  try {
    $pdo = new PDO($dsn, PG_USER, PG_PASS, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
  } catch (Throwable $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    if (getenv('DEBUG')) { http_response_code(500); exit('DB connect failed: '.htmlspecialchars($e->getMessage())); }
    http_response_code(500); exit('Database connection error');
  }
}

if (!function_exists('e')) {
  function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }
}
function csrf_token(){ if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function csrf_check(){ if($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['csrf']??'')!==($_SESSION['csrf']??''))){ http_response_code(422); exit('Invalid CSRF token'); } }
function current_user(){
  if (!empty($_SESSION['user_id'])) {
    $st = db()->prepare('SELECT id,email,username FROM users WHERE id=:id');
    $st->execute([':id'=>(int)$_SESSION['user_id']]);
    return $st->fetch() ?: null;
  } return null;
}
function require_auth(){ if(!current_user()){ header('Location: '.BASE_URL.'login'); exit; } }
function slugify($t){ $t=preg_replace('~[^\pL\d]+~u','-',$t); $t=iconv('utf-8','us-ascii//TRANSLIT',$t); $t=preg_replace('~[^-\w]+~','',$t); $t=trim($t,'-'); $t=preg_replace('~-+~','-',$t); return strtolower($t?:'page'); }

// Use your real absolute paths
if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', __DIR__.'/uploads'); // filesystem
if (!defined('UPLOAD_URI')) define('UPLOAD_URI', rtrim(BASE_URL, '/').'/uploads'); // public URL

if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);


// --- HTTP helpers (curl with safe fallback) ---
function http_get(string $url, int $timeoutMs = 3000): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MusicPages/1.0'
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body ?: null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => max(1, (int)ceil($timeoutMs/1000)),
            'header'  => "User-Agent: MusicPages/1.0\r\n",
        ],
        'ssl' => ['verify_peer'=>true,'verify_peer_name'=>true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}

function http_get_json(string $url, int $timeoutMs = 3000): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'MusicPages/1.0'
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => max(1, (int)ceil($timeoutMs/1000)),
                'header'  => "Accept: application/json\r\nUser-Agent: MusicPages/1.0\r\n",
            ],
            'ssl' => ['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!$body) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

// --- Turn textarea input into JSON links ---
function parse_links_to_json(?string $raw): string {
    $raw = (string)$raw;
    $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw))));
    $out = [];
    foreach ($lines as $line) {
        $label = null; $url = $line;
        if (strpos($line, '|') !== false) {
            [$label, $url] = array_map('trim', explode('|', $line, 2));
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
        $out[] = ['label' => $label ?: null, 'url' => $url];
    }
    return json_encode($out, JSON_UNESCAPED_SLASHES);
}

// --- Optional helpers used by auto-create ---
function download_image_to_uploads(string $url): ?string {
    $bin = http_get($url, 5000);
    if (!$bin) return null;
    $ext = '.jpg';
    $pathPart = parse_url($url, PHP_URL_PATH) ?? '';
    if (preg_match('/\.(png|webp|jpe?g)$/i', $pathPart, $m)) $ext = '.'.strtolower($m[1]);
    $name = 'cover_'.time().'_'.bin2hex(random_bytes(4)).$ext;
    $full = UPLOAD_DIR.$name;
    if (@file_put_contents($full, $bin) === false) return null;
    return UPLOAD_URI.$name;
}

function resolve_link_metadata(string $url): array {
    $meta = [];
    $html = http_get($url, 4000);
    if ($html && preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) $meta['title'] = trim($m[1]);
    if ($html && preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) $meta['image'] = trim($m[1]);
    if ($html && preg_match('/<meta\s+property=["\']og:site_name["\']\s+content=["\']([^"\']+)["\']/i', $html, $m)) $meta['provider'] = trim($m[1]);
    return $meta;
}

function split_title_artist(string $title): array {
    if (preg_match('/^(.*?)\s*[–-]\s*(.*)$/u', $title, $m)) return [trim($m[1]), trim($m[2])];
    return [trim($title), ''];
}

if (!function_exists('time_ago')) {
    function time_ago($ts): string {
        try {
            if ($ts instanceof DateTimeInterface) {
                $t = ($ts instanceof DateTimeImmutable)
                    ? $ts
                    : DateTimeImmutable::createFromInterface($ts);
            } else {
                // Accept Postgres timestamp strings like "2025-09-16 09:13:57.377181+00"
                $t = new DateTimeImmutable((string)$ts);
            }
        } catch (Throwable $e) {
            return '';
        }

        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $diff = $now->getTimestamp() - $t->getTimestamp();
        if ($diff <= 0) return 'just now';

        $units = [
            ['year',   365*24*3600],
            ['month',   30*24*3600],
            ['day',     24*3600],
            ['hour',        3600],
            ['minute',        60],
            ['second',         1],
        ];
        foreach ($units as [$name, $secs]) {
            if ($diff >= $secs) {
                $val = (int) floor($diff / $secs);
                return $val . ' ' . $name . ($val > 1 ? 's' : '') . ' ago';
            }
        }
        return 'just now';
    }
}
