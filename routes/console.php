<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| AI Incident Polling Schedule
|--------------------------------------------------------------------------
|
| Automatically poll for new security incidents every 5 or 10 minutes.
| If there are no new incidents, the command automatically skips AI calls
| to preserve API tokens and avoid unnecessary network calls.
|
*/
if (config('ai.polling.enabled', true)) {
    $interval = (int) config('ai.polling.interval', 5);

    $task = Schedule::command('incidents:poll-analysis')
        ->withoutOverlapping(30)
        ->runInBackground();

    match ($interval) {
        10 => $task->everyTenMinutes(),
        15 => $task->everyFifteenMinutes(),
        30 => $task->everyThirtyMinutes(),
        default => $task->everyFiveMinutes(),
    };
}
