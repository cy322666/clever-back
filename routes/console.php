<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('analytics:refresh-snapshots')->hourly();
Schedule::command('sources:sync --all')->hourly();
Schedule::command('projects:load-telegram')->dailyAt(env('PROJECT_LOAD_TELEGRAM_TIME', '09:00'));
