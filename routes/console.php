<?php

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    /** @var ClosureCommand $this */
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove expired Sanctum tokens (see SANCTUM_EXPIRATION).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Health reminders (pharmacy local time). Duplicates are prevented by notification_deliveries.
Schedule::command('health:send-reminders --vaccines')->dailyAt('09:00')->timezone(config('pharmacy.timezone'))->withoutOverlapping();
Schedule::command('health:send-reminders --medications')->everyFiveMinutes()->withoutOverlapping();

// Ledger entries for points that passed their validity.
Schedule::command('loyalty:record-expirations')->dailyAt('02:00')->timezone(config('pharmacy.timezone'));

// Provider events whose processing failed or died are applied again; stale ones are reported.
Schedule::command('payments:recover-events')->everyFiveMinutes()->withoutOverlapping();
// Refunds sent without an answer from the provider are settled from the provider's records.
Schedule::command('payments:reconcile-refunds')->everyFifteenMinutes()->withoutOverlapping();

// Unpaid online payments past their window: checked with the provider, then expired (order canceled, stock released).
Schedule::command('payments:expire-stale')->everyFiveMinutes()->withoutOverlapping();

// Expired batches: unreserved units leave sellable stock (they were never sold after the expiry date).
Schedule::command('inventory:expire-batches')->dailyAt('00:15')->timezone(config('pharmacy.timezone'))->withoutOverlapping();

// Inventory invariants (balances = ledger = reservations = catalogue stock); failures are logged.
Schedule::command('inventory:verify')->dailyAt('03:00')->timezone(config('pharmacy.timezone'));
