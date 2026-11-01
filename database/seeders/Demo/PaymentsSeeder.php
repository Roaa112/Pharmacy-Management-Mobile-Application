<?php

namespace Database\Seeders\Demo;

use App\Models\Admin;
use App\Models\Payment;
use App\Models\Refund;
use App\Modules\Payments\Services\CodSettlementService;
use App\Modules\Payments\Services\RefundService;
use App\Support\Money;
use Illuminate\Database\Seeder;

/**
 * Payment workflows on the demo orders, through the production services: the accepted return's
 * refund is paid back in cash, and the courier hands over the cash of all but one delivered order
 * (so the reconciliation screen shows both settled and unsettled cash). No online (Paymob)
 * transaction is invented: those only come from the provider.
 */
class PaymentsSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = Admin::value('id');

        if ($refund = Refund::where('status', Refund::PENDING)->orderBy('id')->first()) {
            app(RefundService::class)->process($refund, $adminId ?? 0, 'Cash voucher DEMO-1');
        }

        $collected = Payment::where('provider', Payment::PROVIDER_COD)->where('collected_amount', '>', 0)->whereNull('settled_at')->orderBy('id')->get();

        if ($collected->count() > 1) {
            $settle = $collected->slice(0, $collected->count() - 1);
            $expected = $settle->sum(fn (Payment $p) => Money::toMinor($p->collected_amount));
            // The courier hands over everything; the reconciliation shows no difference.
            app(CodSettlementService::class)->settle($settle->pluck('id')->all(), $expected, null, 'CASH-HANDOVER-1', 'Weekly courier settlement', $adminId);
        }
    }
}
