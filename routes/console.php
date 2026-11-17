<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Webhooks can be lost, so poll anything that has been quiet for a couple of minutes.
Schedule::command('generations:sync')->everyMinute()->withoutOverlapping();
