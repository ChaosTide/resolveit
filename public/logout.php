<?php

require_once __DIR__ . '/../app/bootstrap.php';
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'],
    ]);
}
session_destroy();
if (getenv('APP_ENV') === 'azure') {
    redirect('/.auth/logout');
}
redirect('login.php');
