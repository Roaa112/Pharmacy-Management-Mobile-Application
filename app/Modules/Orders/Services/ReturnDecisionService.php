<?php

namespace App\Modules\Orders\Services;

use App\Models\LoyaltyPointReversal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\ReturnRequestItem;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Points\Services\LoyaltyService;
use App\Modules\Promotions\Services\PromotionRefundPolicy;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Admin decisions on return requests.
 *
 * Accepting a return is three things, done in one transaction or not at all:
 *  1. the refund the admin approves (never more than is left of what the order cost);
 *  2. returned units go to quarantine, never back on sale (decision D1);
 *  3. loyalty points are reversed in proportion to the money refunded (decision D2);
 *  4. a pending refund is created for the approved amount (paid back by RefundService).
 *
 * Problems the admin can fix are reported as DomainException with a readable message.
 */
class ReturnDecisionService
{
    /**
     * @param  string|float|null  $amount  refund to approve; null = the amount the customer asked for
     * @param  array<int|string, int|string|null>  $quantities  order_item_id => units received back
     */
    public function accept(int $returnId, $amount, array $quantities, ?int $adminId): ReturnRequest
    {
        return DB::transaction(function () use ($returnId, $amount, $quantities, $adminId) {
            // Locks make a double-click or two admins accepting at once a no-op for the second one.
            $return = ReturnRequest::lockForUpdate()->findOrFail($returnId);

            if ($return->status !== 'pending') {
                throw new DomainException('This return request was already processed.');
            }

            $order = Order::with('items')->lockForUpdate()->findOrFail($return->order_id);

            $amount = ($amount === null || $amount === '') ? $return->refund_amount : $amount;

            if ($amount === null || !is_numeric($amount) || Money::toMinor($amount) < 0) {
                throw new DomainException('Enter the refund amount to approve.');
            }

            $amountMinor = Money::toMinor($amount);
            $alreadyRefundedMinor = $this->acceptedRefundsMinor($order);

            if ($alreadyRefundedMinor + $amountMinor > Money::toMinor($order->total_price)) {
                throw new DomainException('The refunds of this order would add up to more than the order total.');
            }

            $received = $this->validatedQuantities($order, $quantities);

            // Orders priced with promotions: never refund more than was paid for the returned units,
            // less the gifts and discounts the return makes no longer earned (PromotionRefundPolicy).
            $policy = app(PromotionRefundPolicy::class);
            if ($received && $policy->applies($order)) {
                $quote = $policy->quote($order, $received);
                if ($amountMinor > $quote['max']) {
                    throw new DomainException('At most '.number_format(Money::fromMinor($quote['max']), 2).' can be refunded for these units: '
                        .number_format(Money::fromMinor($quote['returned_value']), 2).' paid for them'
                        .($quote['deduction'] ? ', minus '.number_format(Money::fromMinor($quote['deduction']), 2).' of gifts or discounts no longer earned' : '')
                        .($quote['delivery'] ? ', plus '.number_format(Money::fromMinor($quote['delivery']), 2).' delivery' : '').'.');
                }
            }

            $return->forceFill([
                'status' => 'accepted',
                'approved_amount' => Money::fromMinor($amountMinor),
                'decided_by' => $adminId,
                'decided_at' => now(),
            ])->save();

            foreach ($received as $orderItemId => $quantity) {
                $returnItem = ReturnRequestItem::create([
                    'return_request_id' => $return->id,
                    'order_item_id' => $orderItemId,
                    'quantity' => $quantity,
                ]);

                // Quarantine, not sellable stock: returned medicine is never resold automatically.
                app(InventoryService::class)->receiveCustomerReturn($returnItem, $order->items->find($orderItemId), $quantity, [
                    'admin_id' => $adminId,
                    'reason' => "Return #{$return->id} of order #{$order->id}",
                ]);

                // A promotional gift that came back is recorded as returned (it was quarantined like any unit).
                $line = $order->items->find($orderItemId);
                if ($line->is_gift) {
                    $line->forceFill(['gift_status' => OrderItem::GIFT_RETURNED])->save();
                }
            }

            $reversal = $this->reversePoints($order, $return, $alreadyRefundedMinor + $amountMinor, $adminId);
            if ($reversal) {
                app(LoyaltyService::class)->recordReversal($reversal);
            }

            // The approved amount becomes a refund to pay back (Refunds screen). The payment and the
            // order show "refunded" only once the refund has actually succeeded.
            app(RefundService::class)->requestForReturn($return, $amountMinor, $adminId);

            return $return;
        }, 3);
    }

    public function reject(int $returnId, ?string $note, ?int $adminId): ReturnRequest
    {
        return DB::transaction(function () use ($returnId, $note, $adminId) {
            $return = ReturnRequest::lockForUpdate()->findOrFail($returnId);

            if ($return->status !== 'pending') {
                throw new DomainException('This return request was already processed.');
            }

            $return->forceFill([
                'status' => 'rejected',
                'note' => $note ?? $return->note,
                'decided_by' => $adminId,
                'decided_at' => now(),
            ])->save();

            return $return;
        });
    }

    /**
     * Money already refunded on the order. Returns accepted before approved_amount existed
     * count with the amount the customer asked for.
     */
    private function acceptedRefundsMinor(Order $order): int
    {
        return ReturnRequest::where('order_id', $order->id)
            ->where('status', 'accepted')
            ->get(['approved_amount', 'refund_amount'])
            ->sum(fn ($r) => Money::toMinor($r->approved_amount ?? $r->refund_amount));
    }

    /**
     * @return array<int, int> order_item_id => quantity (> 0 only)
     */
    private function validatedQuantities(Order $order, array $quantities): array
    {
        $received = [];

        foreach ($quantities as $orderItemId => $quantity) {
            if ($quantity === null || $quantity === '') {
                continue;
            }

            $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
            $item = $order->items->find((int) $orderItemId);

            if ($quantity === false || $quantity < 0 || !$item) {
                throw new DomainException('Returned quantities must be whole numbers for lines of this order.');
            }

            if ($quantity === 0) {
                continue;
            }

            $alreadyReturned = (int) ReturnRequestItem::where('order_item_id', $item->id)
                ->whereHas('returnRequest', fn ($q) => $q->where('status', 'accepted'))
                ->sum('quantity');

            // Only dispatched units can come back: a reserved (never shipped) line has nothing in the
            // customer's hands, and quarantining it would create stock out of nothing.
            if (!self::lineWasDispatched($order, $item)) {
                throw new DomainException('One of the lines was never dispatched; cancel the order instead of returning it.');
            }

            if ($alreadyReturned + (int) $quantity > $item->quantity) {
                throw new DomainException('More units would be returned than were ordered for one of the lines.');
            }

            $received[$item->id] = (int) $quantity;
        }

        return $received;
    }

    /**
     * The line's units left the warehouse and are with the customer: fulfilled by dispatch, or a
     * legacy line (stock taken at checkout, no stock_state) of an order that was completed or sent out.
     */
    public static function lineWasDispatched(Order $order, OrderItem $item): bool
    {
        return $item->stock_state === OrderItem::STOCK_FULFILLED
            || ($item->stock_state === null && $order->statusEnum()->isDispatched());
    }

    public static function hasDispatchedUnits(Order $order): bool
    {
        return $order->items->contains(fn (OrderItem $item) => self::lineWasDispatched($order, $item));
    }

    /**
     * Decision D2. Points were earned on the products subtotal; the share of the money paid for
     * products is total_price × subtotal / (subtotal + delivery) (a coupon discounts both parts
     * alike). The points to have reversed after this refund are computed from the cumulative
     * refunded amount and rounded half up once, so several partial refunds add up exactly and
     * never exceed what was earned. A refund that covers that whole share reverses everything.
     */
    private function reversePoints(Order $order, ReturnRequest $return, int $refundedTotalMinor, ?int $adminId): ?LoyaltyPointReversal
    {
        $earned = (int) $order->points_earned;

        if ($earned <= 0) {
            return null;
        }

        $subtotalMinor = $order->items->sum(fn ($item) => Money::toMinor($item->total_price));
        $deliveryMinor = Money::toMinor($order->delivery_fee);
        $paidMinor = Money::toMinor($order->total_price);

        $eligibleMinor = $subtotalMinor + $deliveryMinor > 0
            ? $this->divideRoundHalfUp($paidMinor * $subtotalMinor, $subtotalMinor + $deliveryMinor)
            : 0;

        if ($eligibleMinor <= 0 || $refundedTotalMinor <= 0) {
            // Nothing was paid for the products, or nothing refunded: no money to be proportional to.
            $target = 0;
        } elseif ($refundedTotalMinor >= $eligibleMinor) {
            $target = $earned;
        } else {
            $target = min($earned, $this->divideRoundHalfUp($earned * $refundedTotalMinor, $eligibleMinor));
        }

        $alreadyReversed = (int) LoyaltyPointReversal::where('order_id', $order->id)->sum('points');
        $points = max(0, $target - $alreadyReversed);

        return LoyaltyPointReversal::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'return_request_id' => $return->id,
            'admin_id' => $adminId,
            'points' => $points,
            'refunded_total' => Money::fromMinor($refundedTotalMinor),
            'reversed_total' => $alreadyReversed + $points,
            'points_status_at_reversal' => $this->pointsStatus($order),
        ]);
    }

    private function pointsStatus(Order $order): string
    {
        if ($order->points_status === 'redeemed') {
            return 'redeemed';
        }

        return $order->created_at->lt(app(LoyaltyService::class)->validFrom()) ? 'expired' : 'earned';
    }

    private function divideRoundHalfUp(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
