<?php
namespace App\Modules\DeliveryPrice;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRate;
use App\Models\DeliveryZone;
use App\Support\StoreClock;
use Illuminate\Http\Request;

/**
 * GET /api/v1/user/delivary_prices, kept for existing apps. Delivery prices now come from delivery
 * zones and rates (dashboard: Delivery zones); each active zone is returned in the old row shape
 * (id, governorate, price) with the fee of its rate in effect now.
 */
class DeliveryPriceController extends Controller
{
    public function indexapi(Request $request)
    {
        $now = StoreClock::now();
        $limit = min((int) $request->query('limit', 100) ?: 100, 100);
        $offset = max(0, (int) $request->query('offset', 0));

        $zones = DeliveryZone::where('is_active', true)
            ->whereHas('rates', fn ($q) => $q->effective($now))
            ->with(['rates' => fn ($q) => $q->effective($now)->orderByDesc('priority')->orderBy('id')])
            ->orderBy('name')
            ->skip($offset)->take($limit)
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'Delivery prices fetched successfully.',
            'data' => $zones->map(function (DeliveryZone $zone) {
                /** @var DeliveryRate $rate */
                $rate = $zone->rates->first();

                return [
                    'id' => $zone->id,
                    'governorate' => $zone->name,
                    'price' => number_format((float) $rate->fee, 2, '.', ''),
                    'created_at' => $zone->created_at,
                    'updated_at' => $zone->updated_at,
                    // Added fields.
                    'free_over_amount' => $rate->free_over_amount !== null ? (float) $rate->free_over_amount : null,
                    'min_order_amount' => $rate->min_order_amount !== null ? (float) $rate->min_order_amount : null,
                    'eta_min_days' => $rate->eta_min_days,
                    'eta_max_days' => $rate->eta_max_days,
                ];
            })->values(),
        ]);
    }
}
