<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sla:check')->everyMinute();
Schedule::command('reminder:booking')->dailyAt('07:00');
Schedule::command('reminder:pending')->dailyAt('08:00');
Schedule::command('reminder:csat')->dailyAt('09:00');
Schedule::command('reminder:snooze-check')->everyFiveMinutes();
Schedule::command('tickets:auto-solve')->hourly();
