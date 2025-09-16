<?php
require __DIR__.'/config.php';
header('Content-Type: text/plain');
$log = '/tmp/php-error.log';
if (!file_exists($log)) { echo "(no php-error.log yet)\n"; exit; }
$lines = @file($log);
echo implode('', array_slice($lines ?: [], -200));
