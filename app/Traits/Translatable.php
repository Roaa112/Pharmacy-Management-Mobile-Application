<?php

namespace App\Traits;

trait Translatable
{
    public function getTranslatedAttribute($key)
    {
        $locale = app()->getLocale();
        $field = "{$key}_{$locale}";

        return $this->{$field} ?? $this->{$key . '_en'};
    }
}
