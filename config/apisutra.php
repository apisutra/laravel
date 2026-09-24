<?php

declare(strict_types=1);

return [
    'integration' => [
        'observation' => [
            'enabled' => null,
            'details' => false,
            'max_snapshots' => 256,
            'max_bytes' => 262144,
            'per_window' => 200,
            'window_seconds' => 60,
            'correlation_keys' => ['request_id', 'trace_id'],
            'log_channel' => null,
        ],
    ],
];
