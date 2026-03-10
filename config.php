<?php

declare(strict_types=1);

return [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'notes_app',
        'user' => getenv('DB_USER') ?: 'notes_user',
        'pass' => getenv('DB_PASS') ?: 'notes_password',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Сервис заметок',
        'session_name' => 'notes_session',
        'csrf_header' => 'X-CSRF-Token',
    ],
];
