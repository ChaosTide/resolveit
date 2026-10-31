<?php

// Signs the current visitor out completely: empties the session data, expires the
// session cookie in the browser (not just on the server), and destroys the server-side
// session. The cookie step matters because php's default session_destroy() alone leaves
// the old cookie sitting in the browser, still pointing at a (now-empty) session id.
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
    // Also signs out of the Microsoft account Easy Auth is relying on, not just ResolveIT.
    redirect('/.auth/logout');
}
redirect('login.php');
