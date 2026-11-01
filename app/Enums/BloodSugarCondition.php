<?php

namespace App\Enums;

/**
 * When a blood sugar reading was taken. The app may send the code or the Arabic wording it
 * used before; both are accepted and stored as received (compatibility).
 */
enum BloodSugarCondition: string
{
    case Fasting = 'fasting';
    case BeforeBreakfast = 'before_breakfast';
    case BeforeMeal = 'before_meal';
    case AfterMeal = 'after_meal';
    case Random = 'random';

    public const LEGACY_ARABIC = [
        'صائم' => 'fasting',
        'قبل الإفطار' => 'before_breakfast',
        'قبل الأكل' => 'before_meal',
        'بعد الأكل' => 'after_meal',
        'عشوائي' => 'random',
    ];

    public static function fromInput(string $value): ?self
    {
        return self::tryFrom($value) ?? self::tryFrom(self::LEGACY_ARABIC[$value] ?? '');
    }

    /** Every value the API accepts. */
    public static function acceptedInputs(): array
    {
        return [...array_column(self::cases(), 'value'), ...array_keys(self::LEGACY_ARABIC)];
    }

    public function isFastingLike(): bool
    {
        return in_array($this, [self::Fasting, self::BeforeBreakfast, self::BeforeMeal], true);
    }
}
