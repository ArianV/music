<?php
$u = current_user();
require_auth();

$slug = $_GET['slug'] ?? '';
if ($slug === '') { http_response_code(404); exit('Page not found.'); }

// Fetch the page owned by the current user (no published filter)
$stmt = db()->prepare('SELECT * FROM pages WHERE user_id = :uid AND slug = :slug LIMIT 1');
$stmt->execute([':uid' => (int)$u['id'], ':slug' => $slug]);
$page = $stmt->fetch();
if (!$page) { http_response_code(404); exit('Page not found.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title      = trim($_POST['title']  ?? '');
    $artist     = trim($_POST['artist'] ?? '');
    $new_slug   = trim($_POST['slug']   ?? '') ?: slugify($title);
    $bg_color   = trim($_POST['bg_color'] ?? '#0b0b10');
    $published  = !empty($_POST['published']);
    $links_raw  = $_POST['links_raw'] ?? '';

    $errors = [];
    if ($title === '')  $errors[] = 'Title is required.';
    if ($artist === '') $errors[] = 'Artist name is required.';
    if ($new_slug === '') $errors[] = 'Slug is required.';

    // cover upload (optional)
    $cover_uri = $page['cover_url'] ?? null;
    if (!empty($_FILES['cover']['name'])) {
        $name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/','', $_FILES['cover']['name']);
        $target = UPLOAD_DIR . $name;
        if (is_uploaded_file($_FILES['cover']['tmp_name']) && move_uploaded_file($_FILES['cover']['tmp_name'], $target)) {
            $cover_uri = UPLOAD_URI . $name;
        }
    }

    // links: parse from raw lines → json
    $links_json = parse_links_to_json($links_raw) ?: '[]';
    json_decode($links_json);
    if (json_last_error() !== JSON_ERROR_NONE) $errors[] = 'Links field contains invalid URLs/format.';

    if (!$errors) {
        $upd = db()->prepare('
          UPDATE pages SET
            title = :title,
            artist_name = :artist,
            slug = :slug,
            cover_url = :cover,
            bg_color = :bg,
            links_json = (:links)::jsonb,
            published = :pub,
            updated_at = NOW()
          WHERE id = :id AND user_id = :uid
        ');
        $upd->execute([
          ':title' => $title,
          ':artist' => $artist,
          ':slug' => $new_slug,
          ':cover' => $cover_uri,
          ':bg' => $bg_color ?: '#0b0b10',
          ':links' => $links_json,
          ':pub' => $published,
          ':id' => (int)$page['id'],
          ':uid' => (int)$u['id'],
        ]);
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    } else {
        $error = implode(' ', $errors);
    }
}

// Prefill the paste-links textarea
$existing = json_decode($page['links_json'] ?? '[]', true) ?: [];
$prefill = [];
foreach ($existing as $L) {
    $lab = $L['label'] ?? 'Link';
    $url = $L['url']   ?? '';
    if ($url) $prefill[] = "{$lab} | {$url}";
}
$prefill_text = implode("\n", $prefill);

$title = 'Edit Page';
ob_start(); ?>
<div class="card">
  <h2>Edit Landing Page</h2>
  <?php if (!empty($error)): ?><p style="color:#f87171"><?= e($error) ?></p><?php endif; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <div class="form-row">
      <input type="text" name="title" value="<?= e($page['title']) ?>" placeholder="Song or Project Title" required>
      <input type="text" name="artist" value="<?= e($page['artist_name']) ?>" placeholder="Artist Name" required>
    </div>
    <div class="form-row">
      <input type="text" name="slug" value="<?= e($page['slug']) ?>" placeholder="Slug">
      <input type="text" name="bg_color" value="<?= e($page['bg_color'] ?: '#0b0b10') ?>" placeholder="#0b0b10">
    </div>
    <div class="form-row">
      <label>Cover Image: <input type="file" name="cover" accept="image/*"></label>
      <?php if (!empty($page['cover_url'])): ?>
        <img src="<?= e($page['cover_url']) ?>" class="cover" style="max-width:160px">
      <?php endif; ?>
    </div>
    <div class="form-row">
      <textarea name="links_raw" rows="8" placeholder="One per line; optional 'Label | URL'"><?= e($prefill_text) ?></textarea>
    </div>
    <label><input type="checkbox" name="published" <?= !empty($page['published']) ? 'checked' : '' ?>> Publish</label>
    <div class="form-row"><button>Save Changes</button></div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
