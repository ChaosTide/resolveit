<?php

declare(strict_types=1);

// Loaded by every page in public/ before it does anything else. In order: work out which
// environment we're in, load that environment's settings, load every shared helper module,
// start the session (and expire it if it has been idle too long), open the database
// connection, and make sure the session's user is still a real, active account.

$azureIdentity = null;
if (getenv('APP_ENV') === 'azure') {
    require_once __DIR__ . '/azure-auth.php';
    $azureIdentity = azure_team_identity();
}

// azure:  App Service, Microsoft sign-in, settings from environment variables.
// docker: local Docker Compose stack (deploy/docker), normal password sign-in, settings from environment variables.
// (unset): this PC, settings from app/config.php.
$configFile = in_array(getenv('APP_ENV'), ['azure', 'docker'], true)
    ? __DIR__ . '/config.azure.php'
    : __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Setup required: copy app/config.example.php to app/config.php.');
}

$config = require $configFile;
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/ui.php';

// Cookie settings: JavaScript can never read it (httponly), it is never sent on a
// cross-site navigation except top-level links (samesite=Lax), and the browser only
// sends it over HTTPS when the request actually arrived over HTTPS (secure).
session_name($config['session']['name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => getenv('APP_ENV') === 'azure' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// A session that has sat idle longer than the configured timeout is thrown away and
// replaced with a fresh, empty one, which signs the user out.
$idleSeconds = time() - (int) ($_SESSION['last_activity'] ?? time());
if ($idleSeconds > (int) $config['session']['idle_timeout']) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

$pdo = db($config['db']);
if ($azureIdentity !== null) {
    // Azure: identity comes from Microsoft sign-in, already verified above.
    azure_team_login($pdo, $azureIdentity);
} else {
    // Local/Docker: re-check the session's user against the database on every request,
    // so a role change or deactivation by an administrator takes effect immediately.
    refresh_session_user($pdo);
}
