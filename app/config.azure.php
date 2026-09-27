<?php
declare(strict_types=1);

// Azure settings supply credentials. Never put a cloud password in source code.
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
        'trust_server_certificate' => false,
    ],
    'session' => ['name' => 'cts4911_ticket_session', 'idle_timeout' => 1800],
];
