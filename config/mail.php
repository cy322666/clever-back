<?php

return [
    'default' => env('MAIL_MAILER', 'log'),
    'mailers' => [
        'log' => [
            'driver' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],
    ],
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Owner Analytics'),
    ],
];
