<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('app:collect-metrics')->everyMinute()->withoutOverlapping();
Schedule::command('app:manage-charging')->everyMinute()->withoutOverlapping();
Schedule::command('app:healthcheck')->everyFiveMinutes();
