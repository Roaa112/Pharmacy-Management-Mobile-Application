<?php

namespace app\Modules\Shared\Enums;

enum LanguagesEnum: string
{
    case Arabic = 'ar';
    case English = 'en';

    public static function values(): array
    {
        return array_column(self::cases(),'value');
    }

    public function count() : int 
    {
        return count(self::cases());
    }
}