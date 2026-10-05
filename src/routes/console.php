<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:notify-new-toilets')->hourly();
Schedule::command('app:notify-updated-toilets')->hourly();
Schedule::command('app:discover-places')->hourlyAt(8);
Schedule::command('app:notify-flagged-toilets')->dailyAt('07:00');
