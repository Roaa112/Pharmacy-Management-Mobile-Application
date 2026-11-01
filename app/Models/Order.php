<?php
namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'points_status',
        'address_id',
        'shipping_address',
        'country_id',
        'currency_code',
        'payment_method',
        'payment_method_id',
        'notes',
        'payment_image',
        'payment_status',
        'delivery_fee',
        'subtotal',
        'discount_amount',
        'total_price',
        'gift_description',
        'gift_rule_id',
        'points_earned',
        'coupon_id',
        'status',
        'items_discount_amount',
        'coupon_discount_amount',
        'promotion_discount_amount',
        'delivery_discount_amount',
        'total_savings',
        'coupon_code',
        'pricing_snapshot',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'payment_verified_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'pricing_snapshot' => 'array',
    ];

    /** Online payment methods: the order cannot be confirmed before the payment is verified. */
    public function requiresOnlinePayment(): bool
    {
        return $this->payment_method === Payment::PROVIDER_PAYMOB;
    }

    /** @deprecated use OrderStatus::FLOW */
    public const STATUS_FLOW = OrderStatus::FLOW;

    public function statusEnum(): OrderStatus
    {
        return OrderStatus::from($this->status);
    }

    public function paymentStatusEnum(): PaymentStatus
    {
        return PaymentStatus::tryFrom((string) $this->payment_status) ?? PaymentStatus::Unpaid;
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $next = OrderStatus::tryFrom($newStatus);

        return $next !== null && $this->statusEnum()->canTransitionTo($next);
    }

    /** Plain text; the dashboard renders the "refunded" badge itself. */
    public function getDisplayIdAttribute()
    {
        return "طلب #{$this->id}";
    }

    /**
     * Orders the customer app lists. An order with an accepted return is hidden from the
     * customer's lists, as before (decision D3), but stays in reports and the dashboard.
     */
    public function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->whereDoesntHave('returnRequests', fn ($q) => $q->where('status', 'accepted'));
    }

    /**
     * The delivery address as it was at checkout. Orders from before the snapshot existed fall
     * back to the live address row.
     */
    public function deliveryAddress(): ?array
    {
        if ($this->shipping_address) {
            return $this->shipping_address;
        }

        return $this->address?->only(['governorate', 'city', 'city_id', 'street', 'building', 'apartment', 'landmark', 'address', 'type']);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    /** Snapshots of the promotions, flash sales and coupon applied at checkout. */
    public function promotions()
    {
        return $this->hasMany(OrderPromotion::class)->orderBy('id');
    }

    public function couponRedemption()
    {
        return $this->hasOne(CouponRedemption::class);
    }

    public function promotionRedemptions()
    {
        return $this->hasMany(PromotionRedemption::class);
    }

    public function giftRule()
    {
        return $this->belongsTo(DiscountRule::class, 'gift_rule_id');
    }

    public function returnRequests()
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function pointReversals()
    {
        return $this->hasMany(LoyaltyPointReversal::class);
    }

    public function shipments()
    {
        return $this->hasMany(Shipment::class);
    }

    public function latestShipment()
    {
        return $this->hasOne(Shipment::class)->latestOfMany();
    }

    public function statusHistory()
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /** The current payment attempt (the latest one). */
    public function latestPayment()
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }

    public function paymentVerifier()
    {
        return $this->belongsTo(Admin::class, 'payment_verified_by');
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
