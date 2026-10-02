<?php
// lib/uploads.php — the one place user images get written to disk

const UPLOAD_IMAGE_TYPES = [
  IMAGETYPE_JPEG => 'jpg',
  IMAGETYPE_PNG  => 'png',
  IMAGETYPE_GIF  => 'gif',
  IMAGETYPE_WEBP => 'webp',
];

/**
 * Save an uploaded image from $_FILES[$field].
 * The file type is detected from its contents (never from the client's name),
 * and the stored name is random, so nothing executable can land in /uploads.
 *
 * @return array{0:?string,1:?string} [public URL or null, error message or null]
 *         [null, null] means no file was submitted.
 */
function save_uploaded_image(string $field, string $prefix): array {
  $f = $_FILES[$field] ?? null;
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
  if ($f['error'] !== UPLOAD_ERR_OK) {
    return [null, in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
      ? 'That image is too large.' : 'The upload failed, please try again.'];
  }
  if (!is_uploaded_file($f['tmp_name'])) return [null, 'The upload failed, please try again.'];
  if ($f['size'] > UPLOAD_MAX_MB * 1024 * 1024) return [null, 'That image is too large (max ' . UPLOAD_MAX_MB . ' MB).'];

  $info = @getimagesize($f['tmp_name']);
  $ext  = $info ? (UPLOAD_IMAGE_TYPES[$info[2]] ?? null) : null;
  if (!$ext) return [null, 'Please upload a JPG, PNG, GIF or WebP image.'];

  if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
  $name = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], rtrim(UPLOAD_DIR, '/\\') . '/' . $name)) {
    error_log('[upload] could not write to ' . UPLOAD_DIR);
    return [null, 'The server could not save the image.'];
  }
  return [rtrim(UPLOAD_URI, '/') . '/' . $name, null];
}
