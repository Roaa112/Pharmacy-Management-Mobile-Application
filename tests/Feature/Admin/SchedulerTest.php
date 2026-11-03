<?php

namespace Tests\Feature\Admin;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/** Scheduled tasks run on one server, never overlap, and a killed run cannot block them for a day. */
class SchedulerTest extends TestCase
{
    public function test_every_task_runs_once_globally_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($event) => trim(preg_replace('/^.*artisan["\']?\s+/', '', $event->command)));

        $this->assertEqualsCanonicalizing([
            'sanctum:prune-expired --hours=24', 'health:send-reminders --vaccines', 'health:send-reminders --medications',
            'loyalty:record-expirations', 'payments:recover-events', 'payments:reconcile-refunds', 'payments:expire-stale',
            'inventory:expire-batches', 'inventory:verify',
        ], $events->keys()->all());

        foreach ($events as $command => $event) {
            $this->assertTrue($event->onOneServer, "{$command} runs on one server");
            $this->assertTrue($event->withoutOverlapping, "{$command} never overlaps itself");
            // The overlap lock of a killed run expires before (or with) the run after next.
            $everyMinutes = str_starts_with($event->expression, '*/') ? (int) substr($event->expression, 2) : 1440;
            $this->assertLessThanOrEqual(max(60, $everyMinutes * 2), $event->expiresAt, "{$command} overlap lock expiry");
        }
    }
}
