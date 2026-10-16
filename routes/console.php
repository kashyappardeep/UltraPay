<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ANB deposits ka backup scan: app band ho to bhi payment credit ho jaaye.
Schedule::command('anb:scan-deposits')->everyMinute()->withoutOverlapping();
