<?php
# routes/console.php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tasks:send-reminders')->daily();

Schedule::call(function () {
    $from = now()->toDateString();
    $to = now()->addDays(365)->toDateString();

    Artisan::call('market:holidays:sync', [
        '--from' => $from,
        '--to' => $to,
    ]);
})->dailyAt('03:10');

Schedule::command('market:calendar:generate-sanitary')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('market:calendar:generate-tasks')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('market:holidays:notify')->everyThirtyMinutes();
Schedule::command('marketplace:repair-demo-asset-permissions')->dailyAt('03:25')->withoutOverlapping();

Schedule::command('notifications:health-check --hours=1 --notify')
    ->everyThirtyMinutes()
    ->withoutOverlapping();
