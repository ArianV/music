<?php
// lib/pages.php — shared helpers for song pages (slugs, links, services, URLs)

// Only http(s) URLs with a host are allowed as outbound links.
function safe_http_url(string $url): ?string {
  $url = trim($url);
  if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) return null;
  $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
  if ($scheme !== 'http' && $scheme !== 'https') return null;
  if (!parse_url($url, PHP_URL_HOST)) return null;
  return $url;
}

// Streaming services we recognise: key => [label, needles]
const MUSIC_SERVICES = [
  'spotify'    => ['Spotify',      ['open.spotify.com', 'spotify']],
  'apple'      => ['Apple Music',  ['music.apple.com', 'itunes.apple.com']],
  'soundcloud' => ['SoundCloud',   ['soundcloud.com', 'soundcloud']],
  'youtube'    => ['YouTube',      ['youtube.com', 'youtu.be', 'music.youtube']],
  'amazon'     => ['Amazon Music', ['music.amazon', 'amazon.com/music']],
  'tidal'      => ['TIDAL',        ['tidal.com']],
  'deezer'     => ['Deezer',       ['deezer.com', 'deezer.page.link']],
  'bandcamp'   => ['Bandcamp',     ['bandcamp.com']],
  'audiomack'  => ['Audiomack',    ['audiomack.com']],
];

// Returns the service key (e.g. 'spotify') for a URL, or null.
function detect_service(string $url): ?string {
  $u = strtolower($url);
  foreach (MUSIC_SERVICES as $key => [, $needles]) {
    foreach ($needles as $n) if (str_contains($u, $n)) return $key;
  }
  return null;
}

function service_label(?string $key): ?string {
  return $key ? (MUSIC_SERVICES[$key][0] ?? null) : null;
}

// Turn posted URL inputs into the links_json value stored on a page.
function links_to_json(array $urls): string {
  $out = [];
  foreach ($urls as $url) {
    $url = safe_http_url((string)$url);
    if (!$url) continue;
    $out[] = ['label' => service_label(detect_service($url)), 'url' => $url];
  }
  return json_encode($out, JSON_UNESCAPED_SLASHES);
}

// Decode a page's links, dropping anything that isn't a safe http(s) URL.
function page_links(array $page): array {
  $raw = json_decode($page['links_json'] ?? '[]', true);
  $out = [];
  foreach (is_array($raw) ? $raw : [] as $i => $l) {
    $url = safe_http_url((string)($l['url'] ?? ''));
    if ($url) $out[$i] = ['label' => trim((string)($l['label'] ?? '')), 'url' => $url];
  }
  return $out;
}

// Slugs are globally unique so /s/{slug} always resolves to exactly one page.
function unique_page_slug(PDO $pdo, string $title, string $artist = '', ?int $excludeId = null): string {
  $base = slugify(trim(($artist ? "$artist " : '') . $title), 72);
  $sql  = "SELECT lower(slug) FROM pages WHERE lower(slug) LIKE :like";
  $par  = [':like' => $base . '%'];
  if ($excludeId) { $sql .= " AND id <> :id"; $par[':id'] = $excludeId; }
  $st = $pdo->prepare($sql);
  $st->execute($par);
  $taken = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
  if (!isset($taken[$base])) return $base;
  for ($i = 2; $i <= 200; $i++) { if (!isset($taken["$base-$i"])) return "$base-$i"; }
  return $base . '-' . bin2hex(random_bytes(3));
}

// Public URL for a page.
function page_url(array $p): string {
  $key = !empty($p['slug']) ? $p['slug'] : (string)$p['id'];
  return asset('s/' . rawurlencode($key));
}
