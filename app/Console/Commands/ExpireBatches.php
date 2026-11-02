<?php

namespace App\Console\Commands;

use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Unreserved units of expired batches leave sellable stock (on_hand -> damaged, an "expiry"
 * movement per batch and warehouse). The catalogue already stopped selling them on the expiry
 * date; this records it so they are pulled from the shelf and returned or destroyed.
 */
class ExpireBatches extends Command
{
    protected $signature = 'inventory:expire-batches';

    protected $description = 'Take unreserved units of expired batches off sale (to damaged)';

    public function handle(InventoryService $inventory): int
    {
        $this->info($inventory->expireBatches().' expired unit(s) taken off sale.');

        return self::SUCCESS;
    }
}
