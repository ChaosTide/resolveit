<?php

declare(strict_types=1);

/**
 * Opens the one connection style used everywhere in the app: real (not emulated)
 * prepared statements, exceptions instead of silently-ignored errors, and rows
 * returned as associative arrays. $settings comes from app/config.php (local) or
 * app/config.azure.php (Docker/Azure, read from environment variables).
 */
function db(array $settings): PDO
{
    $dsn = sprintf(
        'sqlsrv:Server=%s;Database=%s;Encrypt=yes;TrustServerCertificate=%s',
        $settings['server'],
        $settings['name'],
        $settings['trust_server_certificate'] ? 'true' : 'false'
    );

    return new PDO($dsn, $settings['user'] ?? null, $settings['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
