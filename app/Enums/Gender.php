<?php

namespace App\Enums;

/**
 * Children's gender. The column stores the Arabic words the app has always sent; the API also
 * accepts the codes and returns gender_code alongside.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    public function storedValue(): string
    {
        return $this === self::Male ? 'ذكر' : 'أنثى';
    }

    public static function fromInput(?string $value): ?self
    {
        return match ($value) {
            'male', 'ذكر' => self::Male,
            'female', 'أنثى' => self::Female,
            default => null,
        };
    }

    public static function acceptedInputs(): array
    {
        return ['male', 'female', 'ذكر', 'أنثى'];
    }
}
