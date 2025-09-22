<?php

namespace App\Models;

use App\Traits\Translatable;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use Translatable;

    protected $fillable = [
        'privacy_policy_ar',
        'privacy_policy_en',
        'terms_conditions_ar',
        'terms_conditions_en',
        'facebook',
        'instagram',
        'tiktok',
        'youtube',
        'phone_number',
        'map_location_url',
        'spend_x',
        'get_y',
        'zaincash'
    ];

    protected $appends = [
        'privacy_policy',
        'terms_conditions',
    ];

    protected $hidden = [
        'privacy_policy_ar',
        'privacy_policy_en',
        'terms_conditions_ar',
        'terms_conditions_en',
    ];

    public function getPrivacyPolicyAttribute()
    {
        return $this->getTranslatedAttribute('privacy_policy');
    }

    public function getTermsConditionsAttribute()
    {
        return $this->getTranslatedAttribute('terms_conditions');
    }
}
