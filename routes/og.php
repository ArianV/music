<?php
// routes/og.php
require_once __DIR__ . '/../config.php';

/**
 * Notes:
 * - Produces a 1200x630 PNG (Open Graph / Twitter).
 * - Tries to use Inter SemiBold if found at assets/Inter-SemiBold.ttf; otherwise falls back to built-in fonts.
 * - Accepts numeric id or slug: /og/123 or /og/lil-foaf-20-min
 */

$key = $GLOBALS['key'] ?? null;
if (!$key) {
  $m = [];
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
  if (preg_match('#^/og/([^/]+)/?$#', $path, $m)) $key = rawurldecode($m[1]);
}
if (!$key) { http_response_code(404); exit; }

$pdo = db();

// Load the page row
if (ctype_digit((string)$key)) {
  $st = $pdo->prepare("SELECT p.*, u.id AS owner_id FROM pages p LEFT JOIN users u ON u.id=p.user_id WHERE p.id=:id LIMIT 1");
  $st->execute([':id'=>(int)$key]);
  $page = $st->fetch();
} else {
  $st = $pdo->prepare("
    SELECT p.*, u.id AS owner_id
    FROM pages p LEFT JOIN users u ON u.id=p.user_id
    WHERE p.slug=:slug
    ORDER BY p.published DESC, p.updated_at DESC NULLS LAST, p.id DESC
    LIMIT 1
  ");
  $st->execute([':slug'=>$key]);
  $page = $st->fetch();
}
if (!$page) { http_response_code(404); exit; }

// Gather fields
$title  = trim((string)($page['title'] ?? 'Untitled'));
$artist = trim((string)($page['artist_name'] ?? $page['artist'] ?? ''));
$cover  = $page['cover_uri'] ?? $page['cover_url'] ?? $page['cover_image'] ?? null;

// ----- Create canvas -----
$W=1200; $H=630;
$im = imagecreatetruecolor($W, $H);

// Colors (PlugBio palette)
$bg   = imagecolorallocate($im, 11, 11, 12);   // #0B0B0C
$card = imagecolorallocate($im, 17, 19, 24);   // #111318
$mint = imagecolorallocate($im, 100, 240, 190);// #64F0BE
$blue = imagecolorallocate($im, 37, 99, 235);  // #2563EB
$fg   = imagecolorallocate($im, 229, 231, 235);// #e5e7eb
$mut  = imagecolorallocate($im, 161, 161, 170);// #a1a1aa
$black= imagecolorallocate($im, 0, 0, 0);

// BG
imagefilledrectangle($im, 0, 0, $W, $H, $bg);

// Card border-ish shadow
imagefilledrectangle($im, 32, 32, $W-32, $H-32, $card);

// ----- Helpers -----
$fontPath = __DIR__ . '/../assets/Inter-SemiBold.ttf';
$hasTtf   = file_exists($fontPath);

function og_load_image(string $uri) {
  // Prefer local path if under UPLOAD_URI or BASE_URL
  $src = $uri;
  if (defined('UPLOAD_URI') && defined('UPLOAD_DIR') && str_starts_with($uri, rtrim(UPLOAD_URI,'/'))) {
    $rel = substr($uri, strlen(rtrim(UPLOAD_URI,'/')));
    $try = rtrim(UPLOAD_DIR,'/') . $rel;
    if (is_file($try)) $src = $try;
  }
  // Load
  if (is_file($src)) {
    $data = @file_get_contents($src);
    return $data ? @imagecreatefromstring($data) : null;
  } else {
    $data = @file_get_contents($src);
    return $data ? @imagecreatefromstring($data) : null;
  }
}

function og_fit_copy($dst, $src, $dx, $dy, $dw, $dh) {
  $sw = imagesx($src); $sh = imagesy($src);
  if ($sw <= 0 || $sh <= 0) return;
  $scale = max($dw/$sw, $dh/$sh);
  $tw = (int)round($sw*$scale); $th = (int)round($sh*$scale);
  $sx = (int)round(($tw - $dw)/2); $sy = (int)round(($th - $dh)/2);
  // Create temp scaled
  $tmp = imagecreatetruecolor($tw, $th);
  imagecopyresampled($tmp, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
  // Crop center into destination
  imagecopy($dst, $tmp, $dx, $dy, $sx, $sy, $dw, $dh);
  imagedestroy($tmp);
}

function og_wrap_ttf($text, $maxWidth, $size, $fontPath) {
  $words = preg_split('/\s+/', $text);
  $lines=[]; $cur='';
  foreach ($words as $w) {
    $try = $cur ? "$cur $w" : $w;
    $box = imagettfbbox($size, 0, $fontPath, $try);
    $wpx = $box[2]-$box[0];
    if ($wpx > $maxWidth && $cur) { $lines[]=$cur; $cur=$w; }
    else { $cur = $try; }
  }
  if ($cur) $lines[]=$cur;
  return $lines;
}

// ----- Layout numbers -----
$pad = 48;
$coverSize = $H - 2*$pad; // square
$left = 48;
$rightX = $left + $coverSize + 40;
$rightW = $W - $rightX - $pad;

// ----- Cover -----
if ($cover && ($img = og_load_image($cover))) {
  og_fit_copy($im, $img, $left, $pad, $coverSize, $coverSize);
  imagedestroy($img);
} else {
  // Placeholder cover
  $ph = imagecreatetruecolor($coverSize, $coverSize);
  imagefilledrectangle($ph, 0, 0, $coverSize, $coverSize, $black);
  // diagonal mint accent
  $mint2 = imagecolorallocate($ph, 70, 210, 170);
  for ($i=0;$i<12;$i++){
    imageline($ph, -50+$i*20, 0, $i*20, $coverSize, $mint2);
  }
  imagecopy($im, $ph, $left, $pad, 0, 0, $coverSize, $coverSize);
  imagedestroy($ph);
}

// ----- Text -----
if ($hasTtf) {
  // Title
  $titleSize = 48;
  $maxTitleW = $rightW;
  $titleLines = og_wrap_ttf($title, $maxTitleW, $titleSize, $fontPath);
  $y = $pad + 40;
  foreach ($titleLines as $i=>$line) {
    imagettftext($im, $titleSize, 0, $rightX, $y, $fg, $fontPath, $line);
    $y += $titleSize + 10;
    if ($i>=2) break; // cap to ~3 lines
  }
  // Artist
  if ($artist) {
    $artistSize = 28;
    imagettftext($im, $artistSize, 0, $rightX, $y+10, $mut, $fontPath, $artist);
    $y += $artistSize + 24;
  }
  // Brand
  $brand = defined('APP_NAME') ? APP_NAME : 'PlugBio';
  imagettftext($im, 24, 0, $rightX, $H - $pad, $mint, $fontPath, $brand);
} else {
  // Fallback: built-in font
  $f = 5; // largest built-in
  imagestring($im, $f, $rightX, $pad + 10, $title, $fg);
  if ($artist) imagestring($im, 4, $rightX, $pad + 40, $artist, $mut);
  imagestring($im, 3, $rightX, $H - $pad, (defined('APP_NAME')?APP_NAME:'PlugBio'), $mint);
}

// Cache headers
header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600, s-maxage=3600');
imagepng($im);
imagedestroy($im);
