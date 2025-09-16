<?php
$u = current_user();
require_auth(); // safety

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim($_POST['title']  ?? '');
    $artist     = trim($_POST['artist'] ?? '');
    $slug       = trim($_POST['slug']   ?? '');
    $bg_color   = trim($_POST['bg_color'] ?? '#0b0b10');
    $published  = !empty($_POST['published']); // checkbox → boolean
    $links_raw  = $_POST['links_raw'] ?? '';

    // defaults/normalize
    if ($slug === '') $slug = slugify($title);

    // basic validation
    $errors = [];
    if ($title === '')  $errors[] = 'Title is required.';
    if ($artist === '') $errors[] = 'Artist name is required.';
    if ($slug === '')   $errors[] = 'Slug is required.';
    if (!$u || empty($u['id'])) $errors[] = 'Not authenticated.';

    // handle cover upload
    $cover_uri = null;
    if (!empty($_FILES['cover']['name'])) {
        $name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/','', $_FILES['cover']['name']);
        $target = UPLOAD_DIR . $name;
        if (is_uploaded_file($_FILES['cover']['tmp_name']) && move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
            $cover_uri = UPLOAD_URI . $name;
        }
    }

    // build links_json from raw lines
    $links_json = parse_links_to_json($links_raw);
    if ($links_json === '' || $links_json === false) $links_json = '[]';
    // (Optional) validate JSON server-side
    json_decode($links_json);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = 'Links field contains invalid URLs/format.';
    }

    if (!$errors) {
        $sql = '
          INSERT INTO pages
            (user_id, title, artist_name, slug, cover_url, bg_color, links_json, published, created_at, updated_at)
          VALUES
            (:user_id, :title, :artist, :slug, :cover, :bg, (:links)::jsonb, :pub, NOW(), NOW())
          RETURNING id
        ';
        $stmt = db()->prepare($sql);
        $stmt->bindValue(':user_id', (int)$u['id'], PDO::PARAM_INT);
        $stmt->bindValue(':title',   $title);
        $stmt->bindValue(':artist',  $artist);
        $stmt->bindValue(':slug',    $slug);
        $stmt->bindValue(':cover',   $cover_uri);                 // null ok
        $stmt->bindValue(':bg',      $bg_color ?: '#0b0b10');
        $stmt->bindValue(':links',   $links_json);                // string → ::jsonb
        $stmt->bindValue(':pub',     $published, PDO::PARAM_BOOL);
        $stmt->execute();

        // $newId = (int)$stmt->fetchColumn(); // if you need it later
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    } else {
        $error = implode(' ', $errors);
    }
}

$title = 'New Page';
ob_start(); ?>
<div class="card">
  <h2>New Landing Page</h2>
  <?php if (!empty($error)): ?><p style="color:#f87171"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-row">
      <input type="text" name="title" placeholder="Song or Project Title" required>
      <input type="text" name="artist" placeholder="Artist Name" required>
    </div>
    <div class="form-row">
      <input type="text" name="slug" placeholder="Custom slug (optional)">
      <input type="text" name="bg_color" value="#0b0b10" placeholder="#0b0b10">
    </div>
    <div class="form-row">
      <label>Cover Image: <input type="file" name="cover" accept="image/*"></label>
    </div>

    <!-- Paste links, one per line. Optional "Label | URL" -->
    <div class="form-row">
      <textarea name="links_raw" rows="6" placeholder="Paste links, one per line.
Examples:
https://open.spotify.com/track/...
Apple Music | https://music.apple.com/album/...
https://youtu.be/ABC123"></textarea>
    </div>

    <label><input type="checkbox" name="published" checked> Publish</label>
    <div class="form-row"><button>Create Page</button></div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
