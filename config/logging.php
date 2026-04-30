<?php

return [
    'default' => env('LOG_CHANNEL', 'stack'),
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => [env('LOG_STACK', 'single')],
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
        ],
        'stderr' => [
            'driver' => 'monolog',
            'handler' => Monolog\Handler\StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
        ],
    ],
];
