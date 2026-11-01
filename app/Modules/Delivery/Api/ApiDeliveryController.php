<?php

namespace App\Modules\Delivery\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Models\Cart;
use App\Modules\Promotions\Services\CartPricingService;
use App\Modules\Delivery\Services\DeliveryQuoteService;
use App\Support\Money;
use Illuminate\Http\Request;

class ApiDeliveryController extends Controller
{
    /** Delivery fee for one of the caller's addresses and their current cart (same as checkout). */
    public function quote(Request $request, DeliveryQuoteService $delivery, CartPricingService $pricing)
    {
        $request->validate(['address_id' => 'required|integer']);

        $address = Address::where('user_id', $request->user()->id)->findOrFail($request->address_id);
        // The items subtotal after item promotions, priced by the engine exactly as checkout prices it.
        $lines = Cart::customerLines()->with(['product', 'size'])->where('user_id', $request->user()->id)->orderBy('id')->get();
        [$priced] = $pricing->price($request->user(), $lines, null, null);
        $subtotalMinor = $priced->subtotal;

        $quote = $delivery->quote($address, $subtotalMinor);

        if (!$quote->ok()) {
            return response()->json([
                'success' => false,
                'code' => $quote->error,
                'message' => api_trans('orders.'.$quote->error, ['amount' => Money::fromMinor($quote->minimumMinor)]),
                'minimum_order' => $quote->minimumMinor ? Money::fromMinor($quote->minimumMinor) : null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'delivery_fee' => Money::fromMinor($quote->feeMinor),
            'free_delivery' => $quote->freeDelivery,
            'free_over_amount' => $quote->rate->free_over_amount !== null ? (float) $quote->rate->free_over_amount : null,
            'eta_min_days' => $quote->rate->eta_min_days,
            'eta_max_days' => $quote->rate->eta_max_days,
            'zone' => $quote->zone->name,
        ]);
    }
}
