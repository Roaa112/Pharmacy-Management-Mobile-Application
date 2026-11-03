<?php

namespace App\Console\Commands;

use App\Modules\Points\Services\LoyaltyService;
use App\Modules\Points\Services\RewardRedemptionService;
use Illuminate\Console\Command;

/**
 * Daily loyalty housekeeping (routes/console.php). Redemption never relies on it: expired points
 * are refused at the moment of use whatever this has done. It only records what happened:
 *  1. pending points whose order now qualifies become usable (if a hook was missed);
 *  2. lots past their expiry are written off ("expired" entries);
 *  3. reward redemptions not collected in time expire (stock and points back).
 * Reads only due rows (indexed status + expiry); safe to run repeatedly.
 */
class RecordLoyaltyExpirations extends Command
{
    protected $signature = 'loyalty:record-expirations';

    protected $description = 'Activate due points, expire lapsed points and uncollected reward redemptions';

    public function handle(LoyaltyService $loyalty, RewardRedemptionService $redemptions): int
    {
        $activated = $loyalty->activateDueLots();
        $expired = $loyalty->recordExpirations();
        $redemptionsExpired = $redemptions->expireStale();

        $this->info("Points activated: {$activated}. Lots expired: {$expired}. Redemptions expired: {$redemptionsExpired}.");

        return self::SUCCESS;
    }
}
