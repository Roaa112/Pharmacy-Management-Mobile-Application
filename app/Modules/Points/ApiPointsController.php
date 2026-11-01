<?php

namespace App\Modules\Points;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Modules\Points\Services\LoyaltyService;
use Illuminate\Http\Request;

/**
 * Customer points. Same numbers as the dashboard (LoyaltyService): points_earned per order is
 * what is left after refund reversals, and orders whose points were fully reversed are not listed.
 */
class ApiPointsController extends Controller
{
    public function __construct(private LoyaltyService $loyalty)
    {
    }

    public function getValidPoints(Request $request)
    {
        $orders = $this->loyalty->valid($request->user()->id)->get();

        return response()->json([
            'valid_points_total' => $orders->sum(fn ($o) => LoyaltyService::net($o)),
            'orders' => $orders->map(fn (Order $order) => $this->row($order) + [
                'expires_at' => $this->loyalty->expiresAt($order)->toDateTimeString(),
            ]),
        ]);
    }

    public function getExpiredPoints(Request $request)
    {
        $orders = $this->loyalty->expired($request->user()->id)->get();

        return response()->json([
            'expired_points_total' => $orders->sum(fn ($o) => LoyaltyService::net($o)),
            'orders' => $orders->map(fn (Order $order) => $this->row($order) + [
                'expired_at' => $this->loyalty->expiresAt($order)->toDateTimeString(),
            ]),
        ]);
    }

    public function getConsumedPoints(Request $request)
    {
        $orders = $this->loyalty->redeemed($request->user()->id)->get();

        return response()->json([
            'consumed_points_total' => $orders->sum(fn ($o) => LoyaltyService::net($o)),
            'orders' => $orders->map(fn (Order $order) => $this->row($order) + [
                'redeemed_at' => $order->updated_at->toDateTimeString(),
            ]),
        ]);
    }

    private function row(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'display_id' => $order->display_id,
            'points_earned' => LoyaltyService::net($order),
            'created_at' => $order->created_at->toDateTimeString(),
            // Added: what the order earned before refunds, and what refunds took back.
            'points_original' => (int) $order->points_earned,
            'points_reversed' => (int) $order->points_reversed,
        ];
    }
}
