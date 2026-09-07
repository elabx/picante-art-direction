<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('media:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('media:clean-inputs')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed')->daily()->withoutOverlapping();
