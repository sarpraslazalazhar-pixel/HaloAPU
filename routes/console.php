<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sla:check')->everyMinute();
