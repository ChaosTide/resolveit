<?php
declare(strict_types=1);

$azureIdentity = null;
if (getenv('APP_ENV') === 'azure') {
    require_once __DIR__ . '/azure-auth.php';
    $azureIdentity = azure_team_identity();
}

$configFile = getenv('APP_ENV') === 'azure'
    ? __DIR__ . '/config.azure.php'
    : __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Setup required: copy app/config.example.php to app/config.php.');
}

$config = require $configFile;
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workflow.php';
require_once __DIR__ . '/audit.php';

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
if ($azureIdentity !== null) azure_team_login($pdo, $azureIdentity);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('The form expired or was invalid. Go back, refresh, and try again.');
    }
}
