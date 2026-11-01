<?php

namespace App\Console\Commands;

use App\Modules\Payments\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * Online payments not completed in time: each is first checked with the provider; still unpaid
 * ones expire and their order is canceled, releasing the reserved stock.
 */
class ExpireStalePayments extends Command
{
    protected $signature = 'payments:expire-stale';

    protected $description = 'Expire unpaid online payments past their window and cancel their orders';

    public function handle(PaymentService $payments): int
    {
        $this->info($payments->expireStale().' payment(s) expired.');

        return self::SUCCESS;
    }
}
