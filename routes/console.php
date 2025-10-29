<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Delete old meter pictures (older than 60 days) - runs daily at 2 AM
Schedule::command('attendance:delete-old-meter-pictures')
    ->daily()
    ->at('02:00')
    ->withoutOverlapping()
    ->onSuccess(function () {
        \Log::info('Old meter pictures cleanup completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Old meter pictures cleanup failed');
    });
