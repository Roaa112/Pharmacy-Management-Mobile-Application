<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Request;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'points_status',
        'address_id',
        'payment_method',
      'notes',
        'payment_image',
        'delivery_fee',
        'total_price',
        'gift_description',
        'gift_rule_id',
        'points_earned',
        'coupon_id',
        'status'
    ];

    /**
     * Forward order of the fulfilment flow. "canceled" is reachable from any non-final status;
     * "completed" and "canceled" are final.
     */
    public const STATUS_FLOW = ['pending', 'confirmed', 'on_delivery', 'completed'];

    public function canTransitionTo(string $newStatus): bool
    {
        if (in_array($this->status, ['completed', 'canceled'], true)) {
            return false;
        }

        if ($newStatus === 'canceled') {
            return true;
        }

        $current = array_search($this->status, self::STATUS_FLOW, true);
        $next = array_search($newStatus, self::STATUS_FLOW, true);

        return $current !== false && $next !== false && $next > $current;
    }
public function getDisplayIdAttribute()
{
    $id = "طلب #{$this->id}";

    if (
        request()->is('web/*') ||
        request()->is('/') ||
        request()->is('dashboard/*') ||
        request()->is('admin/*')
    ) {
        if ($this->returnRequests()->whereIn('status', ['accepted'])->exists()) {
            return "$id <span style='color: red;'>(تم استرجاعه)</span>";
        }
    }

    return $id;
}

    protected static function booted()
    {
        static::addGlobalScope('hide_refunded_orders_for_api', function (Builder $builder) {
            if (Request::is('api/*')) {
                $builder->whereDoesntHave('returnRequests', function ($query) {
                    $query->whereIn('status', ['accepted']);
                });
            }
        });
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
}
