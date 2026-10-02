<?php
// routes/favicon.php — /favicon.ico lives in assets/, but browsers and crawlers ask for it at the site root
header('Content-Type: image/x-icon');
header('Cache-Control: public, max-age=604800');
readfile(__DIR__ . '/../assets/favicon.ico');
