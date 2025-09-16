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

function e($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
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

define('UPLOAD_DIR', __DIR__.'/uploads/');
define('UPLOAD_URI', BASE_URL.'uploads/');
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0777, true);

function http_get(string $url, int $timeoutMs = 2500) {
    // Prefer PHP cURL if available
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MusicLanding/1.0'
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        return $body ?: null;
    }
    // Fallback: streams
    $context = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'timeout' => max(1, (int)ceil($timeoutMs/1000)),
            'header'  => "User-Agent: MusicLanding/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    return $body !== false ? $body : null;
}

function http_get_json(string $url, int $timeoutMs = 2500) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'MusicLanding/1.0'
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => max(1, (int)ceil($timeoutMs/1000)),
                'header'  => "Accept: application/json\r\nUser-Agent: MusicLanding/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
    }
    if (!$body) return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}
