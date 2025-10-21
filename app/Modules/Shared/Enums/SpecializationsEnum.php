<?php

namespace App\Modules\Shared\Enums;

enum SpecializationsEnum: string
{
    case Residential = 'residential';
    case Administrative = 'administrative';
    case Hotel = 'hotel';
    case Commercial = 'commercial';


    public static function values(): array
    {
        return array_column(self::cases(),'value');
    }

    public function count() : int
    {
        return count(self::cases());
    }
}
