<?php
session_destroy();
header('Location: ' . BASE_URL);
exit;
