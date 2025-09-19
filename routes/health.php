<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: text/plain');
try {
  $pdo = db();
  $pdo->query('SELECT 1');
  echo "ok\n";
} catch (Throwable $e) {
  http_response_code(500);
  error_log("[health] DB FAIL: ".$e->getMessage());
  echo "db_error: ".$e->getMessage()."\n";
}
