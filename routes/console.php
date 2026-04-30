<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('analytics:refresh-snapshots')->hourly();
Schedule::command('sources:sync --all')->hourly();
