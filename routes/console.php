<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('gratitude:activate-points')->daily()->withoutOverlapping();
Schedule::command('gratitude:recalculate-levels')->daily()->withoutOverlapping();
Schedule::command('gratitude:expire-points')->daily()->withoutOverlapping();
Schedule::command('gratitude:check-inactivity')->daily()->withoutOverlapping();
Schedule::command('logs:prune --days=60')->daily()->withoutOverlapping();
