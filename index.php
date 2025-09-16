<?php
require_once __DIR__ . '/config.php';

// Exact routes
route('/',            'routes/home.php'); 
route('/login',       'routes/login.php');
route('/logout',      'routes/logout.php');
route('/dashboard',   'routes/dashboard.php');
route('/pages/new',   'routes/pages_new.php');

// Regex routes
route_regex('#^/pages/([^/]+)/edit$#',   'routes/pages_edit.php',   ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/delete$#', 'routes/pages_delete.php', ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/?$#',      'routes/page_public.php',  ['page_key' => 1]);

// Dispatch current request
route(); // call with no args to run dispatcher
