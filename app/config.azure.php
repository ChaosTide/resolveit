<?php
declare(strict_types=1);

// Settings for Azure and the local Docker stack come from environment variables.
// Never put a database password in source code.
foreach (['DB_SERVER', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    if (!getenv($key)) {
        http_response_code(503);
        exit('Cloud setup is incomplete. Contact the project administrator.');
    }
}
return [
    'db' => [
        'server' => getenv('DB_SERVER'),
        'name' => getenv('DB_NAME'),
        'user' => getenv('DB_USER'),
        'password' => getenv('DB_PASSWORD'),
        // Azure SQL has a trusted certificate; the local SQL Server container uses a self-signed one.
        'trust_server_certificate' => getenv('DB_TRUST_SERVER_CERTIFICATE') === 'true',
    ],
    'session' => ['name' => 'cts4911_ticket_session', 'idle_timeout' => 1800],
];
