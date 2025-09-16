<?php
require __DIR__.'/config.php';
echo "<pre>";
echo "PG_HOST=".e(PG_HOST)."\n";
echo "PG_DB=".e(PG_DB)."\n";
echo "PG_USER=".e(PG_USER)."\n";
try {
  $pdo = db();
  echo "Connected!\n";
  $stmt = $pdo->query("select version()");
  echo "Server: ".$stmt->fetchColumn()."\n";
} catch (Throwable $e) {
  echo "Error: ".$e->getMessage()."\n";
}
