<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Provider notifications whose processing failed or died (a killed worker, a timeout) are
 * claimed and applied again. Events still not applied after 15 minutes, or out of automatic
 * retries, are logged as errors: they need a person (Payments → Events).
 */
class RecoverPaymentEvents extends Command
{
    protected $signature = 'payments:recover-events';

    protected $description = 'Re-apply provider payment events that failed or were left processing, and report stale ones';

    public function handle(PaymentService $payments): int
    {
        $stats = $payments->recoverEvents();
        $this->info("Retried {$stats['retried']}, failed again {$stats['failed']}, out of retries {$stats['exhausted']}, skipped {$stats['skipped']}.");

        $stale = $payments->staleEvents();
        if ($stale['stale'] > 0) {
            Log::error('Payment events not applied', $stale);
            $this->error("{$stale['stale']} provider event(s) still not applied (oldest {$stale['oldest']}).");
        }

        return self::SUCCESS;
    }
}
