<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('analytics:refresh-snapshots')->hourly();
Schedule::command('sources:sync --all')->hourly();
Schedule::command('tochka:sync-companies-to-amo', [
    '--from' => env('TOCHKA_COMPANIES_AMO_SYNC_FROM', '2022-01-01'),
    '--poll-attempts' => env('TOCHKA_COMPANIES_AMO_SYNC_POLL_ATTEMPTS', 240),
    '--poll-seconds' => env('TOCHKA_COMPANIES_AMO_SYNC_POLL_SECONDS', 5),
])
    ->dailyAt(env('TOCHKA_COMPANIES_AMO_SYNC_TIME', '06:00'))
    ->withoutOverlapping(360);
Schedule::command('projects:load-telegram')->dailyAt(env('PROJECT_LOAD_TELEGRAM_TIME', '09:00'));
Schedule::command('company:daily-telegram')->dailyAt(env('COMPANY_DAILY_TELEGRAM_TIME', '09:10'));
