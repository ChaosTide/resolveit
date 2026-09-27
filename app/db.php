<?php
declare(strict_types=1);

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
