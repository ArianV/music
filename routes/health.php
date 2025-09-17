<?php
require_once __DIR__ . '/../config.php';
// simple liveness + optional DB ping
try {
  db()->query('SELECT 1');  // comment out if you want pure liveness
  http_response_code(200);
  header('Content-Type: text/plain'); 
  echo "OK";
} catch (Throwable $e) {
  http_response_code(500);
  header('Content-Type: text/plain');
  echo "DB_FAIL";
}
