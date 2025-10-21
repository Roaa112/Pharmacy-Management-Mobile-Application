<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscountRule extends Model
{
   protected $fillable = [
    'discount_type',
    'discount_value',
    'min_amount',
    'min_quantity',
    'free_quantity',
    'gift_type',
    'gift_id',
    'gift_quantity',
    'starts_at',
    'ends_at',
    'applies_to_type',
    'applies_to_id',    
];


  public function targets()
{
    return $this->hasMany(DiscountRuleTarget::class, 'discount_rule_id');
}
}
