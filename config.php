<?php
// ====== config.php

// ---- Load HTML email templates
$tplFile = __DIR__ . '/email_templates.php';   // NOTE: same folder as config.php
if (is_file($tplFile)) {
    require_once $tplFile;
}

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

// ---------- Email + verification helpers ----------

if (!function_exists('is_verified')) {
  function is_verified(?array $u): bool {
    return !empty($u['email_verified_at']);
  }
}

// ===== Email sending: Brevo API (preferred) or SMTP 587 STARTTLS =====
// --- Robust HTML mail sender (tries Composer, then manual includes; HTML + text) ---
if (!function_exists('send_mail')) {
  function send_mail(string $to, string $subject, string $html, ?string $text = null, ?array &$debug = null): bool {
    $debug = $debug ?? [];

    // Try to load PHPMailer (Composer autoload first)
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
      foreach ([
        __DIR__ . '/vendor/autoload.php',        // repo root
        __DIR__ . '/../vendor/autoload.php',     // in case your code is in /var/www/html and vendor is one up
      ] as $autoloader) {
        if (is_file($autoloader)) {
          require_once $autoloader;
          break;
        }
      }
    }

    // Try manual includes if PHPMailer not loaded via Composer
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
      $manual = [
        __DIR__.'/PHPMailer/src/Exception.php',
        __DIR__.'/PHPMailer/src/PHPMailer.php',
        __DIR__.'/PHPMailer/src/SMTP.php',
      ];
      $haveAll = true;
      foreach ($manual as $m) { if (!is_file($m)) { $haveAll = false; break; } }
      if ($haveAll) {
        foreach ($manual as $m) require_once $m;
      }
    }

    // If still not available, fall back to mail() so we don't fatal
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
      // Fallback – not ideal on Railway (no local MTA), but avoids 500
      $from     = getenv('MAIL_FROM') ?: 'do-not-reply@plugbio.app';
      $fromName = getenv('MAIL_FROM_NAME') ?: 'PlugBio';
      $headers  = "MIME-Version: 1.0\r\n";
      $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
      $headers .= "From: ".($fromName ? "$fromName <$from>" : $from)."\r\n";

      $ok = @mail($to, $subject, $html, $headers);
      if (!$ok) $debug[] = 'mail() fallback failed (no PHPMailer/vendor and no local MTA).';
      error_log('[mail] PHPMailer not found — used mail() fallback: '.($ok?'OK':'FAIL'));
      return $ok;
    }

    // PHPMailer path: use SMTP with Brevo
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
      $mail->isSMTP();
      $mail->Host       = getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = getenv('SMTP_USER') ?: '';  // e.g. 974aee001@smtp-brevo.com
      $mail->Password   = getenv('SMTP_PASS') ?: '';  // your Brevo SMTP key
      $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);
      $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;

      $from     = getenv('MAIL_FROM') ?: 'do-not-reply@plugbio.app';
      $fromName = getenv('MAIL_FROM_NAME') ?: 'PlugBio';

      $mail->setFrom($from, $fromName);
      $mail->addAddress($to);

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html;
      $mail->AltBody = $text ?: strip_tags($html);

      $mail->send();
      return true;
    } catch (Throwable $e) {
      $debug[] = $e->getMessage();
      error_log('[mail] send_mail failed: '.$e->getMessage());
      return false;
    }
  }
}



if (!function_exists('smtp_send')) {
  function smtp_send($host,$port,$user,$pass,$from,$fromName,$to,$subject,$html,$text=null,&$debug=[]): bool {
    $debug[] = 'SMTP via '.$host.':'.$port.' (STARTTLS)';
    if (!extension_loaded('openssl')) {
      $debug[] = 'openssl extension missing (TLS required for port 587)';
      return false;
    }
    $ip = @gethostbyname($host);
    $debug[] = 'DNS: '.$host.' -> '.$ip;

    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) { $debug[] = "connect error: $errno $errstr"; return false; }

    $read = function() use ($fp){ $buf=''; while(($ln=fgets($fp, 515))!==false){ $buf.=$ln; if(preg_match('/^\d{3} /',$ln)) break; } return $buf; };
    $want = function($code) use (&$debug,$read){ $resp=$read(); $debug[]="<< ".trim($resp); return (int)substr($resp,0,3)===$code; };
    $say  = function($s) use (&$debug,$fp){ $debug[]=">> $s"; fwrite($fp, $s."\r\n"); };

    if (!$want(220)) { fclose($fp); return false; }
    $say('EHLO plugbio.local');         if (!$want(250)) { fclose($fp); return false; }
    $say('STARTTLS');                   if (!$want(220)) { fclose($fp); return false; }
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { $debug[]='TLS handshake failed'; fclose($fp); return false; }
    $say('EHLO plugbio.local');         if (!$want(250)) { fclose($fp); return false; }

    $say('AUTH LOGIN');                 if (!$want(334)) { fclose($fp); return false; }
    $say(base64_encode($user));         if (!$want(334)) { fclose($fp); return false; }
    $say(base64_encode($pass));         if (!$want(235)) { fclose($fp); return false; }

    $say("MAIL FROM:<{$from}>");        if (!$want(250)) { fclose($fp); return false; }
    $say("RCPT TO:<{$to}>");            if (!$want(250)) { fclose($fp); return false; }
    $say('DATA');                       if (!$want(354)) { fclose($fp); return false; }

    $boundary = 'b'.bin2hex(random_bytes(8));
    $headers = [
      "From: {$fromName} <{$from}>",
      "To: <{$to}>",
      "Subject: ".mb_encode_mimeheader($subject,'UTF-8'),
      "MIME-Version: 1.0",
      "Content-Type: multipart/alternative; boundary=\"{$boundary}\""
    ];
    $plain = $text ?: strip_tags($html);
    $msg  = implode("\r\n",$headers)."\r\n\r\n";
    $msg .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$plain}\r\n";
    $msg .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
    $msg .= "--{$boundary}--\r\n.\r\n";
    $msg = str_replace("\n.", "\n..", $msg); // dot-stuffing

    fwrite($fp,$msg);                   if (!$want(250)) { fclose($fp); return false; }
    $say('QUIT'); fclose($fp);
    return true;
  }
}

// --- VERIFY EMAIL (uses users.verify_token_hash / users.verify_token_expires) ---
if (!function_exists('begin_email_verification')) {
  function begin_email_verification(int $user_id): bool {
    $pdo = db();

    // Get the user (email + display name/handle)
    $st = $pdo->prepare('SELECT email, handle, display_name FROM users WHERE id=:id');
    $st->execute([':id' => $user_id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || empty($u['email'])) return false;

    // Create token (24h)
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $exp   = (new DateTime('+24 hours'))->format('Y-m-d H:i:sP');

    $pdo->prepare('UPDATE users SET verify_token_hash = :h, verify_token_expires = :e WHERE id = :id')
        ->execute([':h' => $hash, ':e' => $exp, ':id' => $user_id]);

    // Build absolute link
    $verifyUrl = rtrim(BASE_URL, '/') . '/verify-email?uid=' . $user_id . '&token=' . $token;

    // Use the branded template (fallback to simple text if missing)
    if (function_exists('render_verify_email')) {
      [$subject, $html, $text] = render_verify_email($u['display_name'] ?? $u['handle'] ?? 'there', $verifyUrl);
    } else {
      $subject = 'Verify your email for PlugBio';
      $html = '<p>Verify your email: <a href="'.e($verifyUrl).'">'.e($verifyUrl).'</a></p>';
      $text = "Verify your email: {$verifyUrl}";
    }

    $dbg = [];
    $ok = send_mail($u['email'], $subject, $html, $text, $dbg);
    if (!$ok) error_log('[mail] verify send failed: '.implode(' | ', $dbg));
    return $ok;
  }
}

// --- EMAIL CHANGE (uses users.pending_email / pending_email_token_hash / pending_email_expires) ---
if (!function_exists('begin_email_change')) {
  function begin_email_change(int $user_id, string $newEmail): bool {
    $pdo = db();

    $st = $pdo->prepare('SELECT handle, display_name FROM users WHERE id=:id');
    $st->execute([':id' => $user_id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    // Create token (24h)
    $token = bin2hex(random_bytes(32));
    $hash  = hash('sha256', $token);
    $exp   = (new DateTime('+24 hours'))->format('Y-m-d H:i:sP');

    $pdo->prepare('UPDATE users
      SET pending_email = :em,
          pending_email_token_hash = :h,
          pending_email_expires = :x
      WHERE id = :id')
      ->execute([':em'=>$newEmail, ':h'=>$hash, ':x'=>$exp, ':id'=>$user_id]);

    $confirmUrl = rtrim(BASE_URL, '/') . '/verify-email-change?uid=' . $user_id . '&token=' . $token;

    // Use a “confirm your new email” template (or reuse verify template with tweaked subject)
    if (function_exists('render_change_email')) {
      [$subject, $html, $text] = render_change_email($u['display_name'] ?? $u['handle'] ?? 'there', $confirmUrl);
    } elseif (function_exists('render_verify_email')) {
      [$subject, $html, $text] = render_verify_email($u['display_name'] ?? $u['handle'] ?? 'there', $confirmUrl);
      $subject = 'Confirm your new email for PlugBio';
      $html = str_replace('Verify your email', 'Confirm your new email', $html);
      $html = str_replace('Verify email', 'Confirm email', $html);
      $text = str_replace('Verify your email', 'Confirm your new email', $text);
      $text = str_replace('Verify email', 'Confirm email', $text);
    } else {
      $subject = 'Confirm your new email for PlugBio';
      $html = '<p>Confirm your new email: <a href="'.e($confirmUrl).'">'.e($confirmUrl).'</a></p>';
      $text = "Confirm your new email: {$confirmUrl}";
    }

    $dbg = [];
    $ok = send_mail($newEmail, $subject, $html, $text, $dbg);
    if (!$ok) error_log('[mail] change-email send failed: '.implode(' | ', $dbg));
    return $ok;
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
