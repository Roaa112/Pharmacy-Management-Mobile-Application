<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountRuleTarget extends Model
{
    protected $fillable = ['discount_rule_id', 'target_type', 'target_id','is_gift'];

    public function discountRule()
    {
        return $this->belongsTo(DiscountRule::class);
    }

}

