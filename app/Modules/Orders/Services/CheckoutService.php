<?php

namespace App\Modules\Orders\Services;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Country;
use App\Models\DiscountRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPromotion;
use App\Models\OrderStatusHistory;
use App\Models\PaymentMethod;
use App\Models\ProductSize;
use App\Models\Setting;
use App\Models\User;
use App\Modules\Inventory\Services\InsufficientStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Models\Payment;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Delivery\Services\DeliveryQuote;
use App\Modules\Delivery\Services\OrderFulfilment;
use App\Modules\Points\Services\LoyaltyService;
use App\Modules\Promotions\Engine\PricingResult;
use App\Modules\Promotions\Services\CartPricingService;
use App\Modules\Promotions\Services\PromotionLifecycle;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a cart into an order in one transaction: lock the customer, the cart and the sizes →
 * check stock → price everything with the promotion engine (items, gifts, coupon, delivery, under
 * the locks) → order, lines (gift lines included), promotion snapshots, usage reservations,
 * stock reservations, shipment, payment → empty the cart. No amount comes from the app, and the
 * stored gift lines are re-decided, never trusted.
 */
class CheckoutService
{
    public function __construct(
        private CartPricingService $pricing,
        private PromotionLifecycle $promotions,
        private LoyaltyService $loyalty,
        private OrderFulfilment $fulfilment,
        private InventoryService $inventory,
        private PaymentService $payments,
    ) {
    }

    public function checkout(User $user, array $input, PaymentMethod $paymentMethod, ?UploadedFile $receipt): JsonResponse
    {
        $address = null;
        if ((int) $input['address_id'] !== -1) {
            // The address must belong to the user placing the order.
            $address = Address::where('user_id', $user->id)->findOrFail($input['address_id']);
        }

        // Receipts are private files (local disk), served only through authorised routes.
        $receiptPath = $receipt?->store('payments', 'local');

        if (!$this->payments->providerReady($paymentMethod->provider)) {
            return response()->json(['message' => api_trans('payments.unavailable'), 'code' => 'payment_unavailable'], 422);
        }

        try {
            // Deadlock retries are safe: nothing outside the database happens inside placeOrder().
            $result = DB::transaction(fn () => $this->placeOrder($user, $input, $paymentMethod, $address, $receiptPath), 3);
        } catch (InsufficientStock $e) {
            // A reservation found fewer units than the pre-check (stock moved between warehouses, or
            // a projection behind the ledger): the same answer as the pre-check, nothing was ordered.
            $result = response()->json(['message' => api_trans('orders.out_of_stock_lines'), 'code' => 'out_of_stock',
                'errors' => [['product' => $e->size?->product?->name ?? api_trans('orders.unknown_product'), 'size' => $e->size?->size ?? '-',
                    'message' => api_trans('orders.line_insufficient', ['available' => max(0, $e->available)])]]], 422);
        }

        if ($result->getStatusCode() !== 201 && $receiptPath) {
            Storage::disk('local')->delete($receiptPath);
        }

        // Online payment: the provider is called after the order is committed (never inside the
        // transaction). If it cannot be reached the order stays pending; the app can retry with
        // POST /api/user/orders/{id}/pay until the payment window ends.
        if ($result->getStatusCode() === 201 && $paymentMethod->provider === Payment::PROVIDER_PAYMOB) {
            $data = $result->getData(true);
            $payment = Payment::where('order_id', $data['order_id'])->latest('id')->firstOrFail();
            $data['payment'] = $this->payments->initiate($payment);
            $data['payment_status'] = Order::whereKey($data['order_id'])->value('payment_status');
            $result->setData($data);
        }

        return $result;
    }

    private function placeOrder(User $user, array $input, PaymentMethod $paymentMethod, ?Address $address, ?string $receiptPath): JsonResponse
    {
        // The customer row, then the cart rows, are locked so concurrent or repeated checkouts
        // (and cart changes) cannot oversell stock or turn one cart into two orders.
        User::whereKey($user->id)->lockForUpdate()->first();
        $cartItems = Cart::with('product')->where('user_id', $user->id)->lockForUpdate()->get();
        $customerLines = $cartItems->where('is_gift', false)->values();

        if ($customerLines->isEmpty()) {
            return response()->json(['message' => api_trans('orders.cart_empty')], 422);
        }

        $couponCode = $input['coupon_code'] ?? null;

        // Gifts are decided now, from the promotions live now, never from the stored gift lines.
        // A first pass finds the gift sizes; then every size involved is locked in id order (the
        // order every stock change uses, so concurrent checkouts wait instead of deadlocking).
        $customerSizes = ProductSize::whereIn('id', $customerLines->pluck('product_size_id')->filter())->get()->keyBy('id');
        $customerLines->each(fn (Cart $line) => $line->setRelation('size', $customerSizes->get($line->product_size_id)));
        [$preview] = $this->pricing->price($user, $customerLines, $couponCode, null);
        $sizeIds = $customerLines->pluck('product_size_id')->merge(array_map(fn ($g) => $g['size']->id, $preview->gifts))->filter()->unique()->sort()->values();

        $sizes = ProductSize::whereIn('id', $sizeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $customerLines->each(fn (Cart $line) => $line->setRelation('size', $sizes->get($line->product_size_id)));

        if ($stockErrors = $this->stockErrors($customerLines, $sizes)) {
            return response()->json(['message' => api_trans('orders.out_of_stock_lines'), 'errors' => $stockErrors], 422);
        }

        // Every price, discount and gift recalculated from the database by the engine, with the
        // locked stock and live usage counts (limited promotions and the coupon are locked too).
        $stock = $sizes->map(fn (ProductSize $size) => (int) $size->stock)->all();
        [$result, $quote] = $this->pricing->price($user, $customerLines, $couponCode, $address, true, $stock, forCheckout: true);

        if ($address && $quote && !$quote->ok()) {
            return $this->deliveryError($quote);
        }

        if ($result->blocking) {
            return response()->json(['message' => api_trans('promotions.gift_blocked'), 'code' => 'gift_unavailable', 'pricing' => $result->toArray()], 422);
        }

        if (isset($input['expected_total']) && Money::toMinor($input['expected_total']) !== $result->total()) {
            return response()->json(['message' => api_trans('promotions.pricing_changed'), 'code' => 'pricing_changed', 'pricing' => $result->toArray()], 409);
        }

        // A coupon the customer entered must apply, or nothing is ordered: never charge more than the
        // customer expects by dropping it silently (a better automatic offer is the one exception).
        if (filled($couponCode) && !$result->coupon && ($result->couponRejection['reason'] ?? null) !== 'conflict_better_offer') {
            $reason = $result->couponRejection['message'] ?? api_trans('promotions.reasons.invalid');

            return response()->json(['message' => api_trans('orders.coupon_rejected', ['reason' => $reason]), 'code' => 'coupon_rejected',
                'reason' => $result->couponRejection['reason'] ?? 'invalid', 'pricing' => $result->toArray()], 422);
        }

        $subtotalMinor = $result->subtotal;
        $deliveryMinor = $result->deliveryFee;
        $settings = Setting::current();
        $firstGift = $result->gifts[0] ?? null;

        // Points are earned on the products subtotal (before delivery and coupon).
        // (float) keeps the JSON number type the app already receives.
        $pointsEarned = (float) $this->loyalty->pointsFor($subtotalMinor, $settings);

        $coupon = $result->coupon;
        $country = Country::default();

        $order = Order::create([
            'user_id' => $user->id,
            'country_id' => $country?->id,
            'currency_code' => $country?->currency_code,
            'address_id' => $address?->id,
            'shipping_address' => $address?->snapshot(),
            'payment_method' => $paymentMethod->code,
            'payment_method_id' => $paymentMethod->id,
            'payment_image' => $receiptPath,
            'delivery_fee' => Money::fromMinor($deliveryMinor),
            'subtotal' => Money::fromMinor($subtotalMinor),
            'discount_amount' => Money::fromMinor($result->orderDiscount()),
            'total_price' => Money::fromMinor($result->total()),
            'items_discount_amount' => Money::fromMinor($result->itemsDiscount()),
            'coupon_discount_amount' => Money::fromMinor($result->couponDiscount),
            'promotion_discount_amount' => Money::fromMinor($result->promotionDiscount),
            'delivery_discount_amount' => Money::fromMinor($result->deliveryDiscount),
            'total_savings' => Money::fromMinor($result->totalSavings()),
            'coupon_code' => $coupon?->code,
            'pricing_snapshot' => $result->toArray(),
            'gift_description' => $firstGift ? __('orders.gift_description', ['product' => $firstGift['size']->product->name_ar ?? $firstGift['size']->product->name_en], 'ar') : null,
            'gift_rule_id' => $firstGift ? $firstGift['promotion']->id : null,
            'coupon_id' => $coupon?->id,
            'points_earned' => $pointsEarned,
            'notes' => $input['notes'] ?? null,
        ]);

        $lineIds = [];
        foreach ($customerLines as $item) {
            $line = $result->line($item->id);
            $size = $sizes->get($item->product_size_id);

            $orderItem = OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'product_size_id' => $item->product_size_id,
                'quantity' => $line->quantity,
                'price_at_time' => Money::fromMinor($line->unit),
                'original_price' => Money::fromMinor($line->originalUnit),
                'total_price' => Money::fromMinor($line->total()),
                'discount_amount' => Money::fromMinor($line->discount()),
                'discount_rule_id' => $line->promotionId,
                'flash_sale_id' => $line->flashSaleId,
                'promotion_snapshot' => $line->applied ? ['promotions' => $line->toArray()['promotions']] : null,
                // Snapshot so the order keeps what was bought even if the product is renamed or deleted.
                'product_name_ar' => $item->product->name_ar,
                'product_name_en' => $item->product->name_en,
                'size_label' => $size->size,
            ]);
            $lineIds[(string) $item->id] = $orderItem->id;

            // The units stay in the warehouse, held for this order until dispatch or cancellation.
            $this->inventory->reserve($orderItem, ['user_id' => $user->id]);
        }

        $giftLineIds = [];
        foreach ($result->gifts as $gift) {
            $giftLineIds[$gift['gift_key']] = $this->addGiftLine($order, $gift, $user->id)->id;
        }

        $this->snapshotPromotions($order, $result, $lineIds, $giftLineIds);
        $this->promotions->reserve($order, $result);

        if ($address && $quote) {
            $this->fulfilment->createShipment($order->setRelation('user', $user), $address, $quote);
        }

        // The payment record (COD: due on delivery; Paymob: pending until the provider confirms).
        $this->payments->createForCheckout($order, $paymentMethod, $receiptPath);
        // Only the projected payment status: a full refresh would turn the money fields into DB strings in the response.
        $order->payment_status = Order::whereKey($order->id)->value('payment_status');

        OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => null, 'to_status' => 'pending', 'actor_type' => OrderWorkflow::ACTOR_CUSTOMER, 'actor_id' => $user->id]);
        $this->loyalty->recordEarn($order);

        // Customer lines and gift lines alike: the order now holds them.
        Cart::where('user_id', $user->id)->delete();

        return response()->json([
            'message' => api_trans('orders.created'),
            'order_id' => $order->id,
            'total_price' => $order->total_price,
            'points_earned' => $pointsEarned,
            'gift_description' => $order->gift_description,
            // Added fields (older apps ignore them).
            'subtotal' => $order->subtotal,
            'delivery_fee' => $order->delivery_fee,
            'discount' => $order->discount_amount,
            'items_discount' => $order->items_discount_amount,
            'coupon_discount' => $order->coupon_discount_amount,
            'promotion_discount' => $order->promotion_discount_amount,
            'delivery_discount' => $order->delivery_discount_amount,
            'total_savings' => $order->total_savings,
            'gifts' => array_map(fn ($gift) => $result->giftToArray($gift), $result->gifts),
            'applied_promotions' => array_map(fn ($a) => $result->appliedToArray($a), $result->applied),
            'currency' => $order->currency_code,
            'payment_status' => $order->payment_status,
        ], 201);
    }

    /** A promotional gift: a real order line at price 0, reserved like any other line. */
    private function addGiftLine(Order $order, array $gift, int $userId): OrderItem
    {
        $size = $gift['size'];
        $promotion = $gift['promotion'];
        $reward = $gift['reward'];

        $line = OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $size->product_id,
            'product_size_id' => $size->id,
            'quantity' => $gift['quantity'],
            'price_at_time' => 0,
            'original_price' => Money::fromMinor($gift['original_unit']),
            'total_price' => 0,
            'discount_amount' => Money::fromMinor($gift['original_unit'] * $gift['quantity']),
            'is_gift' => true,
            'gift_status' => OrderItem::GIFT_GRANTED,
            'discount_rule_id' => $promotion->id,
            'promotion_reward_id' => $reward->id,
            'promotion_snapshot' => [
                'promotion_id' => $promotion->id,
                'promotion_name' => $promotion->name,
                'promotion_type' => $promotion->discount_type,
                'reward_id' => $reward->id,
                'reward_type' => $reward->reward_type,
                'reward_threshold' => $reward->threshold_amount !== null ? (float) $reward->threshold_amount : null,
                'requested_quantity' => $gift['requested_quantity'],
                'original_price' => Money::fromMinor($gift['original_unit']),
            ],
            'product_name_ar' => $size->product->name_ar,
            'product_name_en' => $size->product->name_en,
            'size_label' => $size->size,
        ]);

        // A real line at price 0: reserved now, leaves stock as a "gift" movement at dispatch.
        $this->inventory->reserve($line, ['user_id' => $userId, 'reason' => "Gift, promotion #{$promotion->id}, order #{$order->id}"]);

        return $line;
    }

    /** Immutable snapshots of every applied promotion, flash sale and coupon (order_promotions). */
    private function snapshotPromotions(Order $order, PricingResult $result, array $lineIds, array $giftLineIds): void
    {
        foreach ($result->applied as $applied) {
            $model = $applied['model'] ?? null;
            $gifts = array_values(array_filter($result->gifts, fn ($g) => $applied['source'] === 'promotion' && $g['promotion']->id === $applied['id']));
            $details = $applied['details'] ?? [];
            $details['order_item_ids'] = array_values(array_filter(array_map(fn ($key) => $lineIds[(string) $key] ?? null, $details['lines'] ?? [])));
            unset($details['lines']);

            if ($model instanceof DiscountRule) {
                $details += [
                    'priority' => $model->priority,
                    'repeatable' => (bool) $model->repeatable,
                    'tier_mode' => $model->tier_mode,
                    'min_quantity' => $model->min_quantity,
                    'min_amount' => $model->min_amount !== null ? (float) $model->min_amount : null,
                    'qualifying_basis' => $model->qualifying_basis,
                    'targets' => $model->targets->map(fn ($t) => $t->only(['target_type', 'target_id', 'is_excluded']))->values()->all(),
                ];
                if (in_array($model->discount_type, [DiscountRule::TYPE_BUY_X_GET_Y, DiscountRule::TYPE_SPEND_X_GET_Y, DiscountRule::TYPE_FREE_DELIVERY], true) && !$details['order_item_ids']) {
                    $details['order_item_ids'] = $this->qualifyingLineIds($model, $result, $lineIds);
                }
            }

            $details['gifts'] = array_map(fn ($g) => [
                'order_item_id' => $giftLineIds[$g['gift_key']] ?? null,
                'reward_id' => $g['reward']->id,
                'product_id' => $g['size']->product_id,
                'product_size_id' => $g['size']->id,
                'quantity' => $g['quantity'],
                'unit_value' => Money::fromMinor($g['original_unit']),
                'threshold' => $g['reward']->threshold_amount !== null ? (float) $g['reward']->threshold_amount : null,
            ], $gifts);

            OrderPromotion::create([
                'order_id' => $order->id,
                'source' => $applied['source'],
                'discount_rule_id' => $applied['source'] === 'promotion' ? $applied['id'] : null,
                'flash_sale_id' => $applied['source'] === 'flash_sale' ? $applied['id'] : null,
                'coupon_id' => $applied['source'] === 'coupon' ? $applied['id'] : null,
                'promotion_type' => $applied['type'],
                'name' => $applied['name'],
                'code' => $applied['code'] ?? null,
                'eligible_amount' => Money::fromMinor($applied['eligible'] ?? 0),
                'discount_amount' => Money::fromMinor($applied['discount'] ?? 0),
                'delivery_discount_amount' => Money::fromMinor($applied['delivery_discount'] ?? 0),
                'reward_quantity' => $applied['reward_quantity'] ?? 0,
                'details' => $details,
            ]);
        }
    }

    /** Buy X counts undiscounted units of its targets (unless stackable); spend promotions every eligible line. */
    private function qualifies(DiscountRule $promotion, $line): bool
    {
        if ($promotion->discount_type === DiscountRule::TYPE_BUY_X_GET_Y) {
            return \App\Modules\Promotions\Engine\TargetMatcher::matches($promotion->targets, $line->product, $line->size, false)
                && ($promotion->is_stackable || !$line->isDiscounted());
        }

        return \App\Modules\Promotions\Engine\TargetMatcher::matches($promotion->targets, $line->product, $line->size, true);
    }

    /** Order lines that counted toward a gift or spend promotion (for returns). */
    private function qualifyingLineIds(DiscountRule $promotion, PricingResult $result, array $lineIds): array
    {
        return array_values(array_filter(array_map(
            fn ($line) => $this->qualifies($promotion, $line) ? ($lineIds[(string) $line->key] ?? null) : null,
            $result->lines,
        )));
    }

    private function stockErrors($cartItems, $sizes): array
    {
        $errors = [];

        foreach ($cartItems as $item) {
            $size = $sizes->get($item->product_size_id);
            $available = ($size && $item->product && $size->product_id == $item->product_id) ? $size->stock : 0;

            if ($available <= 0) {
                $errors[] = ['product' => $item->product->name ?? api_trans('orders.unknown_product'), 'size' => $size->size ?? '-', 'message' => api_trans('orders.line_out_of_stock')];
            } elseif ($item->product_quantity > $available) {
                $errors[] = ['product' => $item->product->name ?? api_trans('orders.unknown_product'), 'size' => $size->size ?? '-', 'message' => api_trans('orders.line_insufficient', ['available' => $available])];
            }
        }

        return $errors;
    }

    private function deliveryError(DeliveryQuote $quote): JsonResponse
    {
        if ($quote->error === DeliveryQuote::BELOW_MINIMUM) {
            return response()->json([
                'message' => api_trans('orders.below_minimum', ['amount' => Money::fromMinor($quote->minimumMinor)]),
                'code' => $quote->error,
                'minimum_order' => Money::fromMinor($quote->minimumMinor),
            ], 422);
        }

        return response()->json(['message' => api_trans('orders.city_not_covered'), 'code' => $quote->error], 422);
    }
}
