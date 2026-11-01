<?php

namespace App\Models;

use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use Translatable;

    protected $fillable = [
        'country_id', 'code', 'name_ar', 'name_en', 'requires_receipt', 'account_name', 'account_number',
        'instructions_ar', 'instructions_en', 'is_active', 'sort_order', 'provider', 'is_legacy',
    ];

    protected $casts = ['requires_receipt' => 'boolean', 'is_active' => 'boolean', 'is_legacy' => 'boolean'];

    /** Methods that can be offered to customers: COD and Paymob. Legacy methods stay for history only. */
    public const ACTIVE_PROVIDERS = ['cod', 'paymob'];

    /** Retired (ZainCash, Qi Card): kept for historical orders only, never offered or configured. */
    public function isRetired(): bool
    {
        return (bool) $this->is_legacy || !in_array($this->provider, self::ACTIVE_PROVIDERS, true);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function getNameAttribute()
    {
        return $this->getTranslatedAttribute('name');
    }

    /** Methods offered at checkout (the store sells in Egypt only, in EGP). */
    public function scopeOffered($query)
    {
        return $query->where('is_active', true)->where('is_legacy', false)->whereIn('provider', self::ACTIVE_PROVIDERS)
            ->orderBy('sort_order')->orderBy('id');
    }
}
