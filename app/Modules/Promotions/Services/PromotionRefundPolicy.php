<?php

namespace App\Modules\Promotions\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRequestItem;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * How much a return may refund on an order priced with promotions, from the order's own
 * snapshots (never from today's promotions):
 *
 *  - a returned unit refunds what was actually paid for it: its line price minus its share of
 *    the order-level item discounts (automatic + the coupon's items part), spread over the paid
 *    lines in proportion to their totals;
 *  - gift lines refund nothing (they cost nothing);
 *  - when the return makes a gift no longer earned (Buy X: fewer qualifying units kept; Spend X:
 *    the kept qualifying spend falls below the gift's threshold), the gift's value is deducted,
 *    unless the gift itself comes back; a Spend X discount no longer earned is deducted too;
 *  - the delivery actually paid becomes refundable once every paid unit has been returned.
 *
 * Deductions are cumulative over the order's accepted returns and each is taken once. The
 * result never exceeds what is left of the order total (ReturnDecisionService checks that too).
 */
class PromotionRefundPolicy
{
    /**
     * Orders priced by the promotion engine (they carry a pricing snapshot) that used a
     * promotion, a coupon or a gift. Older orders keep the order-total cap only.
     */
    public function applies(Order $order): bool
    {
        return $order->pricing_snapshot !== null && ($order->promotions()->exists() || $order->items->contains('is_gift', true));
    }

    /**
     * @param  array<int, int>  $quantities  order_item_id => units returned by this return
     * @return array{max: int, returned_value: int, deduction: int, delivery: int} minor units
     */
    public function quote(Order $order, array $quantities): array
    {
        $order->loadMissing(['items', 'promotions']);
        $previous = $this->previouslyReturned($order);
        $cumulative = $previous;
        foreach ($quantities as $id => $qty) {
            $cumulative[$id] = ($cumulative[$id] ?? 0) + $qty;
        }

        $unitPaid = $this->paidPerLine($order);
        $returnedValue = 0;
        foreach ($quantities as $id => $qty) {
            $item = $order->items->find($id);
            if ($item && !$item->is_gift && $item->quantity > 0) {
                $returnedValue += $this->share($unitPaid[$id] ?? 0, $qty, $item->quantity);
            }
        }

        $deduction = max(0, $this->lostRewards($order, $cumulative, $unitPaid) - $this->lostRewards($order, $previous, $unitPaid));

        $paidItems = $order->items->where('is_gift', false);
        $allBack = $paidItems->every(fn (OrderItem $item) => ($cumulative[$item->id] ?? 0) >= $item->quantity);
        $wasAllBack = $paidItems->every(fn (OrderItem $item) => ($previous[$item->id] ?? 0) >= $item->quantity);
        $delivery = $allBack && !$wasAllBack ? $this->deliveryPaid($order) : 0;

        return [
            'max' => max(0, $returnedValue - $deduction + $delivery),
            'returned_value' => $returnedValue,
            'deduction' => $deduction,
            'delivery' => $delivery,
        ];
    }

    /** @return array<int, int> paid line total (minor) per paid order line id */
    public function paidPerLine(Order $order): array
    {
        $lines = $order->items->where('is_gift', false)->values();
        $totals = $lines->mapWithKeys(fn (OrderItem $i) => [$i->id => Money::toMinor($i->total_price)])->all();
        $base = array_sum($totals);
        $discount = min($base, Money::toMinor($order->promotion_discount_amount) + $this->couponItemsDiscount($order));

        if ($base <= 0 || $discount <= 0) {
            return $totals;
        }

        // Largest remainder, so the shares add up to the discount exactly.
        $shares = [];
        $remainders = [];
        foreach ($totals as $id => $total) {
            $shares[$id] = intdiv($discount * $total, $base);
            $remainders[$id] = ($discount * $total) % $base;
        }
        arsort($remainders);
        $left = $discount - array_sum($shares);
        foreach (array_keys($remainders) as $id) {
            if ($left-- <= 0) {
                break;
            }
            $shares[$id]++;
        }

        return array_map(fn ($id) => $totals[$id] - $shares[$id], array_combine(array_keys($totals), array_keys($totals)));
    }

    /** Value of rewards no longer earned once $returned units are back (minor units). */
    private function lostRewards(Order $order, array $returned, array $paid): int
    {
        $lost = 0;

        foreach ($order->promotions->where('source', 'promotion') as $snapshot) {
            $details = $snapshot->details ?? [];
            $lineIds = $details['order_item_ids'] ?? [];
            $gifts = $details['gifts'] ?? [];

            if ($snapshot->promotion_type === 'buy_x_get_y' && $gifts) {
                $required = max(1, (int) ($details['required_quantity'] ?? $details['min_quantity'] ?? 1));
                $times = max(1, (int) ($details['times'] ?? 1));
                $kept = collect($lineIds)->sum(fn ($id) => max(0, (int) ($order->items->find($id)?->quantity ?? 0) - ($returned[$id] ?? 0)));
                $keptTimes = min($times, !empty($details['repeatable']) ? intdiv($kept, $required) : ($kept >= $required ? 1 : 0));
                foreach ($gifts as $gift) {
                    $lostUnits = intdiv((int) $gift['quantity'] * ($times - $keptTimes), $times);
                    $lost += $this->giftClawback($gift, $lostUnits, $returned);
                }
            }

            if ($snapshot->promotion_type === 'amount_gift') {
                $qualifying = Money::toMinor($details['qualifying_amount'] ?? $snapshot->eligible_amount);
                $returnedSpend = collect($lineIds ?: array_keys($paid))->sum(fn ($id) => $this->share($paid[$id] ?? 0, $returned[$id] ?? 0, (int) ($order->items->find($id)?->quantity ?? 1)));
                $kept = $qualifying - $returnedSpend;

                foreach ($gifts as $gift) {
                    $threshold = Money::toMinor($gift['threshold'] ?? $details['min_amount'] ?? 0);
                    if ($kept < $threshold) {
                        $lost += $this->giftClawback($gift, (int) $gift['quantity'], $returned);
                    }
                }

                $thresholds = array_map(fn ($t) => Money::toMinor($t), $details['thresholds'] ?? []);
                if ((float) $snapshot->discount_amount > 0 && $thresholds && $kept < min($thresholds)) {
                    $lost += Money::toMinor($snapshot->discount_amount);
                }
            }
        }

        return $lost;
    }

    /** A lost gift costs its value, minus the units of it that come back. */
    private function giftClawback(array $gift, int $lostUnits, array $returned): int
    {
        $back = (int) ($returned[$gift['order_item_id'] ?? 0] ?? 0);

        return max(0, $lostUnits - $back) * Money::toMinor($gift['unit_value'] ?? 0);
    }

    private function couponItemsDiscount(Order $order): int
    {
        $coupon = Money::toMinor($order->coupon_discount_amount);
        $deliveryPart = Money::toMinor($order->pricing_snapshot['coupon']['delivery_discount'] ?? 0);

        return max(0, $coupon - $deliveryPart);
    }

    /** Delivery the customer actually paid: fee − delivery promotion − the coupon's delivery part. */
    private function deliveryPaid(Order $order): int
    {
        return max(0, Money::toMinor($order->delivery_fee) - Money::toMinor($order->delivery_discount_amount)
            - Money::toMinor($order->pricing_snapshot['coupon']['delivery_discount'] ?? 0));
    }

    /** @return array<int, int> order_item_id => units already returned by accepted returns */
    private function previouslyReturned(Order $order): array
    {
        return ReturnRequestItem::query()
            ->whereIn('order_item_id', $order->items->pluck('id'))
            ->whereHas('returnRequest', fn ($q) => $q->where('status', 'accepted'))
            ->groupBy('order_item_id')
            ->selectRaw('order_item_id, sum(quantity) as units')
            ->pluck('units', 'order_item_id')
            ->map(fn ($u) => (int) $u)
            ->all();
    }

    /** $quantity of $lineQuantity units of a line worth $lineMinor, rounded half up. */
    private function share(int $lineMinor, int $quantity, int $lineQuantity): int
    {
        if ($lineQuantity <= 0 || $quantity <= 0) {
            return 0;
        }

        return intdiv($lineMinor * min($quantity, $lineQuantity) * 2 + $lineQuantity, 2 * $lineQuantity);
    }
}
