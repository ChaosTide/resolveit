<?php

declare(strict_types=1);

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

session_name($config['session']['name']);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => getenv('APP_ENV') === 'azure' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > (int) $config['session']['idle_timeout']) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

$pdo = db($config['db']);
if ($azureIdentity !== null) {
    azure_team_login($pdo, $azureIdentity);
} else {
    refresh_session_user($pdo);
}
