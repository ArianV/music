<?php
// routes/feed.php — home page: hero (logged out) + latest published pages

require_once __DIR__ . '/../config.php';

// Public feed; no auth required.

// Query newest public pages (you can later switch to a trending score)
$sql = "
  SELECT
    p.id,
    p.title,
    p.artist_name,
    p.slug,
    p.cover_uri,
    COALESCE(p.updated_at, p.created_at) AS ts,
    u.handle
  FROM pages p
  JOIN users u ON u.id = p.user_id
  WHERE p.published
  ORDER BY COALESCE(p.updated_at, p.created_at) DESC
  LIMIT :n
";
$st = db()->prepare($sql);
$st->bindValue(':n', FEED_SIZE, PDO::PARAM_INT);
$st->execute();
$pages = $st->fetchAll(PDO::FETCH_ASSOC);

// helper: initial for placeholder
function initial_for(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? strtoupper(mb_substr($s, 0, 1)) : '♪';
}

$me    = current_user();
$title = $me ? 'Discover · PlugBio' : 'PlugBio · Smart links for your music';
meta_set([
  'url'    => asset(''),   // "/" and "/feed" are the same page; tell search engines which address to use
  'jsonld' => [
    '@context'    => 'https://schema.org',
    '@type'       => 'WebSite',
    'name'        => 'PlugBio',
    'url'         => asset(''),
    'description' => 'Free smart links for musicians: one page per release with every streaming platform, plus click analytics.',
  ],
]);
$play  = '<svg viewBox="0 0 24 24"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z"/></svg>';

ob_start();
?>
<?php if (!$me): ?>
  <section class="hero">
    <div class="eyebrow"><b>●</b> One link for every platform</div>
    <h1>Share your music <span class="gradient-text">everywhere</span>, with one link.</h1>
    <p>Build a beautiful landing page for every release. Fans pick their favorite streaming service, and you see what they click.</p>
    <div class="cta-row">
      <a class="btn btn-primary btn-lg" href="<?= e(asset('register')) ?>">Create your first page</a>
      <a class="btn btn-lg" href="#discover">See examples</a>
    </div>
    <div class="services" aria-label="Supported services">
      <span>Spotify</span><span>Apple Music</span><span>YouTube</span><span>SoundCloud</span><span>TIDAL</span><span>Deezer</span><span>Bandcamp</span>
    </div>
  </section>
<?php endif; ?>

<section id="discover">
  <div class="section-head">
    <div>
      <h2><?= $me ? 'Discover' : 'Fresh releases' ?></h2>
      <div class="muted small">The latest pages from artists on PlugBio</div>
    </div>
    <?php if ($me): ?><a class="btn btn-primary" href="<?= e(asset('pages/new')) ?>">+ New page</a><?php endif; ?>
  </div>

  <?php if (!$pages): ?>
    <div class="empty-state">
      <h3>No releases yet</h3>
      <p>Be the first to share a song.</p>
      <a class="btn btn-primary" href="<?= e(asset($me ? 'pages/new' : 'register')) ?>">Create a page</a>
    </div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($pages as $p): ?>
        <a class="card-link" href="<?= e(page_url($p)) ?>">
          <?php if (!empty($p['cover_uri'])): ?>
            <div class="card-media"><img src="<?= e($p['cover_uri']) ?>" alt="" loading="lazy"><span class="play"><?= $play ?></span></div>
          <?php else: ?>
            <div class="card-media placeholder"><?= e(initial_for($p['artist_name'] ?? $p['title'] ?? '')) ?><span class="play"><?= $play ?></span></div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= e($p['title'] ?: 'Untitled') ?></div>
            <div class="card-meta"><?= e($p['artist_name'] ?: '@' . $p['handle']) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
