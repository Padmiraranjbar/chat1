<?php
declare(strict_types=1);

// Harden session handling before starting the session.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
 'lifetime' => 0,
 'path' => '/',
 'secure' => $isHttps,
 'httponly' => true,
 'samesite' => 'Strict'
]);
session_start();

// Security headers. CSP is intentionally not set yet because legacy pages contain inline UI code.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

spl_autoload_register(static function(string $class): void {
 $prefix='App\\';
 if(strncmp($class,$prefix,strlen($prefix))!==0)return;
 $relative=substr($class,strlen($prefix));
 $file=__DIR__.'/app/'.str_replace('\\','/',$relative).'.php';
 if(is_file($file))require $file;
});
require __DIR__.'/app/Router.php';
(new App\\Router())->dispatch();
