<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * ═══════════════════════════════════════════════════════════════════════════
 * RETENTION, SCHEDULED — Task 34 D3.
 *
 * The activity log grows with every publish, every deletion and every
 * sign-in. Unbounded, that is a table that is fine for a year and then is
 * the reason a backup takes an hour.
 *
 * Declared here rather than left to a runbook, because a retention policy
 * that depends on somebody remembering is a retention policy that stops the
 * day they leave. The windows and their reasoning are in
 * App\Console\Commands\PruneActivityLog.
 *
 * Nightly, at a time nothing else runs. It needs a scheduler — `php artisan
 * schedule:work` in development, a single cron entry in production — and
 * that requirement belongs in the handover notes.
 */
Schedule::command('activity:prune')
    ->dailyAt('03:40')
    ->onOneServer()
    ->description('Delete activity-log entries past their retention window');
