<?php
require_once __DIR__ . '/config.php';

// Exact routes
route('/',            'home.php');
route('/login',       'login.php');
route('/logout',      'logout.php');
route('/dashboard',   'dashboard.php');
route('/pages/new',   'pages_create.php');

// Regex routes
route_regex('#^/pages/([^/]+)/edit$#',   'pages_edit.php',   ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/delete$#', 'pages_delete.php', ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/?$#',      'page_public.php',  ['page_key' => 1]);

// Dispatch current request
route(); // call with no args to run dispatcher
