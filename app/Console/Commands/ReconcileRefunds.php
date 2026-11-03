<?php

namespace App\Console\Commands;

use App\Models\Refund;
use App\Modules\Payments\Services\RefundService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Refunds with an unknown outcome are settled from the provider's record of the transaction. */
class ReconcileRefunds extends Command
{
    protected $signature = 'payments:reconcile-refunds';

    protected $description = 'Reconcile refunds sent to the provider without a usable answer';

    public function handle(RefundService $refunds): int
    {
        $outcomes = [];

        if ($stale = $refunds->flagStaleProcessing()) {
            Log::warning('Refunds left processing without a provider answer were marked unknown', ['count' => $stale]);
        }

        // Unknown refunds, and refunds the provider answered "pending" that have not settled by callback.
        Refund::where('status', Refund::UNKNOWN)
            ->orWhere(fn ($q) => $q->where('status', Refund::PROCESSING)->whereNotNull('provider_refund_id')->where('updated_at', '<', now()->subMinutes(30)))
            ->orderBy('id')->each(function (Refund $refund) use ($refunds, &$outcomes) {
            $outcome = $refunds->reconcile($refund)['outcome'];
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        });

        $this->info($outcomes ? json_encode($outcomes) : 'No refunds to reconcile.');

        if (($outcomes['ambiguous'] ?? 0) + ($outcomes['cannot_check'] ?? 0) + ($outcomes['not_seen'] ?? 0) > 0) {
            Log::error('Refunds need manual reconciliation', $outcomes);
        }

        return self::SUCCESS;
    }
}
