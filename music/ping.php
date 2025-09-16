<?php
require __DIR__.'/config.php';
if (isset($_GET['set'])) {
  $_SESSION['ping'] = 'pong';
  echo "set; now <a href='".BASE_URL."ping.php'>reload</a>";
  exit;
}
echo 'session ping: '.($_SESSION['ping'] ?? 'missing').' (path='.($_SERVER['REQUEST_URI']).')';
