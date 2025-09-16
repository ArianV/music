<?php
$title = 'Music Landing';
ob_start();
?>
  <div class="landing">
    <div class="box card">
      <h1>Create Modern, link-in-bio style music pages</h1>
      <p class="small">Artists can sign up, add a cover, and publish a smart landing page with links to Spotify, Apple Music, YouTube, SoundCloud, and more.</p>
      <div class="meta">
        <a class="btn" href="<?= BASE_URL ?>register">Get started</a>
      </div>
    </div>
  </div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
