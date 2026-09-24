<?php

declare(strict_types=1);

return [
    'redis' => [
        'client' => 'phpredis',
        'apisutra_cooldown' => [
            'host' => env('APISUTRA_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('APISUTRA_REDIS_PORT', 6379),
            'database' => 0,
            'timeout' => 2,
            'read_timeout' => 2,
            'max_retries' => 0,
        ],
        'apisutra' => [
            'host' => env('APISUTRA_REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('APISUTRA_REDIS_PORT', 6379),
            'database' => 0,
            'timeout' => 2,
            'read_timeout' => 2,
            'max_retries' => 0,
        ],
    ],
];
