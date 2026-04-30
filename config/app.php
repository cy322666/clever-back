<?php

return [
    'name' => env('APP_NAME', 'Owner Analytics'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'asset_url' => env('ASSET_URL'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Kaliningrad'),
    'locale' => env('APP_LOCALE', 'ru'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'ru_RU'),
    'cipher' => 'AES-256-CBC',
];
