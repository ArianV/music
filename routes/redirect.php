<?php
$to = $_GET['to'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Fallback: derive a stable id from the target URL if none provided
if ($id <= 0) {
    if ($to) {
        $id = (int) (hexdec(substr(hash('crc32b', $to), 0, 8)) ?: 1);
    } else {
        $id = 1; // final fallback
    }
}

// Log click (named params)
$stmt = db()->prepare('
  INSERT INTO link_clicks (link_id, clicked_at, ip)
  VALUES (:id, NOW(), :ip)
');
$stmt->execute([
  ':id' => $id,
  ':ip' => $_SERVER['REMOTE_ADDR'] ?? null
]);

// Only allow http(s) targets
if (!preg_match('#^https?://#i', $to)) {
    header('Location: ' . BASE_URL);
    exit;
}
header('Location: ' . $to);
exit;
