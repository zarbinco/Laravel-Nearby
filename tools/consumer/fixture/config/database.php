<?php

declare(strict_types=1);

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql', 'host' => env('DB_HOST'), 'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD', ''),
            'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true,
        ],
    ],
];
