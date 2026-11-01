<?php

namespace App\Console\Commands;

use App\Modules\Points\Services\LoyaltyService;
use Illuminate\Console\Command;

class RecordLoyaltyExpirations extends Command
{
    protected $signature = 'loyalty:record-expirations';

    protected $description = 'Write ledger "expire" entries for points that passed their validity (balances are not changed by this)';

    public function handle(LoyaltyService $loyalty): int
    {
        $this->info('Expired orders recorded: '.$loyalty->recordExpirations());

        return self::SUCCESS;
    }
}
