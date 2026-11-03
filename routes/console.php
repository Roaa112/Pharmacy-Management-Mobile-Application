<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    /** @var ClosureCommand $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every task runs on one server only (onOneServer: an atomic lock in the shared cache store,
// CACHE_STORE=database or redis on every server) and never twice at once (withoutOverlapping). The
// overlap lock expires after the given minutes, so a run killed mid-way does not block the next
// ones for the default 24 hours. Every task is also safe to run twice (row locks, unique keys).

// Remove expired Sanctum tokens (see SANCTUM_EXPIRATION).
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer()->withoutOverlapping(60);

// Health reminders (pharmacy local time). Duplicates are prevented by notification_deliveries.
Schedule::command('health:send-reminders --vaccines')->dailyAt('09:00')->timezone(config('pharmacy.timezone'))->onOneServer()->withoutOverlapping(60);
Schedule::command('health:send-reminders --medications')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);

// Ledger entries for points that passed their validity.
Schedule::command('loyalty:record-expirations')->dailyAt('02:00')->timezone(config('pharmacy.timezone'))->onOneServer()->withoutOverlapping(60);

// Provider events whose processing failed or died are applied again; stale ones are reported.
Schedule::command('payments:recover-events')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);
// Refunds sent without a final answer from the provider are settled from the provider's records.
Schedule::command('payments:reconcile-refunds')->everyFifteenMinutes()->onOneServer()->withoutOverlapping(15);

// Unpaid online payments past their window: checked with the provider, then expired (order canceled, stock released).
Schedule::command('payments:expire-stale')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);

// Expired batches: unreserved units leave sellable stock (they were never sold after the expiry date).
Schedule::command('inventory:expire-batches')->dailyAt('00:15')->timezone(config('pharmacy.timezone'))->onOneServer()->withoutOverlapping(60);

// Inventory invariants (balances = ledger = reservations = catalogue stock); failures are logged.
Schedule::command('inventory:verify')->dailyAt('03:00')->timezone(config('pharmacy.timezone'))->onOneServer()->withoutOverlapping(60);
