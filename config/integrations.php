<?php

return [
    'default_sync_window_days' => env('INTEGRATION_DEFAULT_SYNC_WINDOW_DAYS', 30),

    'sources' => [
        'amo' => [
            'name' => 'amoCRM',
            'driver' => 'amo',
            'enabled' => true,
        ],
        'weeek' => [
            'name' => 'Weeek',
            'driver' => 'weeek',
            'enabled' => true,
        ],
        'tochka' => [
            'name' => 'Точка Банк',
            'driver' => 'tochka',
            'enabled' => true,
        ],
        'bank' => [
            'name' => 'Ручной импорт банка',
            'driver' => 'bank-import',
            'enabled' => true,
        ],
    ],
];
