<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// شبكة أمان لمؤقّتات اللعب: لا تعتمد عليها في الإيقاع، فقط في التعافي.
Schedule::command('games:sweep')->everyMinute()->withoutOverlapping();
