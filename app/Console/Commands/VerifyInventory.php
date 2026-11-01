<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\InventoryIntegrity;
use Illuminate\Console\Command;

/** Read-only check that balances, ledger, reservations and catalogue stock agree. */
class VerifyInventory extends Command
{
    protected $signature = 'inventory:verify';

    protected $description = 'Check that stock balances, the stock ledger, reservations and catalogue stock agree (read-only)';

    public function handle(InventoryIntegrity $integrity): int
    {
        $problems = $integrity->problems();

        if (!$problems) {
            $this->info('Inventory is consistent.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
