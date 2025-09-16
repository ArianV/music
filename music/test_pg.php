<?php
require __DIR__ . '/config.php';
try {
  $pdo = db();
  echo "Connected via PDO pgsql OK";
} catch (Throwable $e) {
  echo $e->getMessage();
}
