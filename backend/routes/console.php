<?php

use App\Domains\Advertising\Models\AdEventLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(static function (): void {
    AdEventLog::pruneOlderThan(now()->subDays(90));
})->daily()->name('ads:prune-event-logs');
