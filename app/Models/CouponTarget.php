<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Products, variants, brands or categories a coupon is limited to (or excluded from). */
class CouponTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['coupon_id', 'target_type', 'target_id', 'is_excluded'];

    protected $casts = ['is_excluded' => 'boolean', 'target_id' => 'integer'];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }
}
