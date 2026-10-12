<?php
/* Expose only the public API, admin page and its two static assets. */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$routes = array(
    '/' => 'index.php',
    '/index.php' => 'index.php',
    '/admin.php' => 'admin.php',
    '/sendsms.php' => 'sendsms.php',
);

header('X-Content-Type-Options: nosniff');
if (isset($routes[$path])) {
    require __DIR__ . '/' . $routes[$path];
    return true;
}

$assets = array('/admin.css' => 'text/css; charset=utf-8', '/admin.js' => 'application/javascript; charset=utf-8');
if (isset($assets[$path]) && in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'), true)) {
    header('Content-Type: ' . $assets[$path]);
    header('Cache-Control: no-cache');
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        readfile(__DIR__ . $path);
    }
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found.';
return true;
