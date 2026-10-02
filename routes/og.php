<?php
// routes/og.php — /og/{id}.png: the 1200x630 share image used by link previews (Open Graph / Twitter)
require_once __DIR__ . '/../config.php';

$key = (string)($GLOBALS['og_key'] ?? '');
if ($key === '') not_found();

// Load the page row (only published pages get a share image)
$pdo = db();
if (ctype_digit($key)) {
  $st = $pdo->prepare("SELECT * FROM pages WHERE id=:id AND published LIMIT 1");
  $st->execute([':id'=>(int)$key]);
} else {
  $st = $pdo->prepare("SELECT * FROM pages WHERE lower(slug)=lower(:slug) AND published
                       ORDER BY updated_at DESC NULLS LAST, id DESC LIMIT 1");
  $st->execute([':slug'=>$key]);
}
$page = $st->fetch();
if (!$page) not_found();

$title    = trim((string)($page['title'] ?? '')) ?: 'Untitled';
$artist   = trim((string)($page['artist_name'] ?? ''));
$cover    = page_cover($page);
$services = array_values(array_unique(array_filter(array_map(
  fn($l) => service_label(detect_service($l['url'])), page_links($page)))));

// Rendering is the heaviest thing the site does, so keep the PNG on disk until the page changes.
$version   = 'v3'; // bump when the design below changes, so old cached images are replaced
$cacheDir  = dirname(DB_PATH) . '/og-cache';
$cacheFile = $cacheDir . '/' . (int)$page['id'] . '-' . md5($version . $page['updated_at'] . $title . $artist . $cover . implode(',', $services)) . '.png';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
if (is_file($cacheFile)) { readfile($cacheFile); exit; }

/* ---------- drawing helpers ---------- */

const OG_W = 1200, OG_H = 630;
$fontBold = __DIR__ . '/../assets/fonts/SpaceGrotesk-Bold.ttf';
$fontText = __DIR__ . '/../assets/fonts/Inter-Medium.ttf';

// Only reads files from our own uploads folder; never fetches remote URLs.
function og_load_image(?string $uri) {
  if (!$uri) return null;
  $pos  = strpos($uri, '/uploads/');
  $name = $pos !== false ? substr($uri, $pos + 9) : null;
  if (!$name || !preg_match('/^[A-Za-z0-9._-]+$/', $name)) return null;
  $path = rtrim(UPLOAD_DIR, '/\\') . '/' . $name;
  $data = is_file($path) ? @file_get_contents($path) : false;
  return $data ? @imagecreatefromstring($data) : null;
}

// Scale + center-crop $src into a $dw x $dh box at ($dx,$dy).
function og_cover_fit($dst, $src, int $dx, int $dy, int $dw, int $dh): void {
  $sw = imagesx($src); $sh = imagesy($src);
  $scale = max($dw / $sw, $dh / $sh);
  $cw = (int)round($dw / $scale); $ch = (int)round($dh / $scale);
  imagecopyresampled($dst, $src, $dx, $dy, (int)(($sw - $cw) / 2), (int)(($sh - $ch) / 2), $dw, $dh, $cw, $ch);
}

// The bundled fonts cover Latin-1; turn anything else into its closest plain letter (Ē → E).
function og_text(string $s): string {
  return preg_replace_callback('/[^\x{0000}-\x{00FF}\x{2000}-\x{206F}]/u', function ($m) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $m[0]);
    return $t === false ? '' : $t;
  }, $s);
}

function og_width(string $s, float $size, string $font): int {
  $b = imagettfbbox($size, 0, $font, $s);
  return $b[2] - $b[0];
}

// Word-wrap into at most $maxLines lines, adding "…" if the text doesn't fit.
function og_wrap(string $text, float $size, string $font, int $maxW, int $maxLines): array {
  $lines = []; $cur = '';
  foreach (preg_split('/\s+/', $text) as $w) {
    $try = $cur === '' ? $w : "$cur $w";
    if ($cur !== '' && og_width($try, $size, $font) > $maxW) { $lines[] = $cur; $cur = $w; }
    else $cur = $try;
  }
  if ($cur !== '') $lines[] = $cur;
  if (count($lines) > $maxLines) {
    $lines = array_slice($lines, 0, $maxLines);
    $last = $lines[$maxLines - 1] . '…';
    while (og_width($last, $size, $font) > $maxW && mb_strlen($last) > 2) $last = mb_substr($last, 0, -2) . '…';
    $lines[$maxLines - 1] = $last;
  }
  return $lines;
}

/* ---------- compose ---------- */

$im  = imagecreatetruecolor(OG_W, OG_H);
imagealphablending($im, true);
$rgb = fn(int $r, int $g, int $b, int $a = 0) => imagecolorallocatealpha($im, $r, $g, $b, $a);
$img = og_load_image($cover);

// Background: the cover, blurred and darkened (or the brand gradient when there's no cover)
if ($img) {
  // shrink, blur, then enlarge in smooth steps so the result is a soft glow, not blocks
  $bg = imagecreatetruecolor(120, 63);
  og_cover_fit($bg, $img, 0, 0, 120, 63);
  for ($i = 0; $i < 6; $i++) imagefilter($bg, IMG_FILTER_GAUSSIAN_BLUR);
  foreach ([[400, 210], [OG_W, OG_H]] as [$w, $h]) {
    $next = imagescale($bg, $w, $h, IMG_BICUBIC);
    imagedestroy($bg); $bg = $next;
    for ($i = 0; $i < 4; $i++) imagefilter($bg, IMG_FILTER_GAUSSIAN_BLUR);
  }
  imagecopy($im, $bg, 0, 0, 0, 0, OG_W, OG_H);
  imagedestroy($bg);
  imagefilledrectangle($im, 0, 0, OG_W, OG_H, $rgb(7, 7, 11, 30));   // ~75% dark overlay
} else {
  for ($x = 0; $x < OG_W; $x++) {
    $t = $x / OG_W;
    imageline($im, $x, 0, $x, OG_H, $rgb((int)(20 + 30 * $t), (int)(60 - 30 * $t), (int)(55 + 60 * $t)));
  }
}

// Cover art on the left, with a thin light border
$pad = 70; $size = OG_H - 2 * $pad;
imagefilledrectangle($im, $pad - 2, $pad - 2, $pad + $size + 1, $pad + $size + 1, $rgb(255, 255, 255, 105));
if ($img) {
  og_cover_fit($im, $img, $pad, $pad, $size, $size);
  imagedestroy($img);
} else {
  imagefilledrectangle($im, $pad, $pad, $pad + $size, $pad + $size, $rgb(17, 17, 24));
  $note = '♪';
  imagettftext($im, 150, 0, $pad + (int)(($size - og_width($note, 150, $fontText)) / 2), $pad + (int)($size * 0.68), $rgb(110, 242, 195), $fontText, $note);
}

// Text column on the right
$x = $pad + $size + 60; $maxW = OG_W - $x - $pad;
$white = $rgb(244, 244, 246); $muted = $rgb(190, 190, 205); $mint = $rgb(110, 242, 195);

// Brand at the top
imagefilledellipse($im, $x + 7, $pad + 13, 14, 14, $mint);
imagettftext($im, 22, 0, $x + 24, $pad + 22, $white, $fontBold, 'PlugBio');

// Title (big, up to 3 lines) and artist, vertically centered as a block
$titleSize = mb_strlen($title) > 28 ? 48 : 60;
$lines = og_wrap(og_text($title), $titleSize, $fontBold, $maxW, 3);
$lineH = (int)($titleSize * 1.18);
$blockH = count($lines) * $lineH + ($artist ? 56 : 0);
$y = (int)((OG_H - $blockH) / 2) + $titleSize;
foreach ($lines as $line) { imagettftext($im, $titleSize, 0, $x, $y, $white, $fontBold, $line); $y += $lineH; }
if ($artist) {
  $artistLine = og_wrap(og_text($artist), 30, $fontText, $maxW, 1)[0];
  imagettftext($im, 30, 0, $x, $y + 14, $muted, $fontText, $artistLine);
}

// Where to listen, at the bottom
// (as many services as fit cleanly, rather than trailing off)
$shown = array_slice($services, 0, 4);
do { $listen = $shown ? 'Listen on ' . implode(' · ', $shown) : 'Listen now'; }
while (og_width($listen, 21, $fontText) > $maxW && array_pop($shown) !== null);
imagettftext($im, 21, 0, $x, OG_H - $pad, $mint, $fontText, $listen);

// Save to the cache (dropping this page's older versions), then send
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0770, true);
foreach (glob($cacheDir . '/' . (int)$page['id'] . '-*.png') ?: [] as $old) @unlink($old);
if (@imagepng($im, $cacheFile)) readfile($cacheFile); else imagepng($im);
imagedestroy($im);
