<?php

namespace App\Modules\Promotions\Engine;

use App\Models\Product;
use App\Models\ProductSize;
use Illuminate\Support\Collection;

/**
 * Whether an item is covered by a set of targets (product, variant, brand, category), with
 * exclusions. Shared by promotions and coupons, so "eligible item" means the same everywhere.
 */
final class TargetMatcher
{
    /**
     * @param  Collection|iterable  $targets  rows with target_type, target_id, is_excluded (and is_gift, ignored here)
     * @param  bool  $emptyMeansAll  no included target = every item (spend X, free delivery, coupons)
     */
    public static function matches(iterable $targets, Product $product, ProductSize $size, bool $emptyMeansAll): bool
    {
        return static::specificity($targets, $product, $size, $emptyMeansAll) !== null;
    }

    /**
     * Specificity of the most specific included target matching the item (variant 4 … category 1),
     * 0 when the empty target list means "everything", null when the item is not covered.
     */
    public static function specificity(iterable $targets, Product $product, ProductSize $size, bool $emptyMeansAll): ?int
    {
        $weights = config('promotions.specificity');
        $included = 0;
        $best = null;

        foreach ($targets as $target) {
            if (!empty($target->is_gift)) {
                continue;
            }

            $hit = static::hit($target->target_type, (int) $target->target_id, $product, $size);

            if (!empty($target->is_excluded)) {
                if ($hit) {
                    return null;
                }
                continue;
            }

            $included++;
            if ($hit) {
                $best = max($best ?? 0, $weights[$target->target_type] ?? 0);
            }
        }

        if ($included === 0) {
            return $emptyMeansAll ? 0 : null;
        }

        return $best;
    }

    private static function hit(string $type, int $id, Product $product, ProductSize $size): bool
    {
        return match ($type) {
            'variant' => $size->id === $id,
            'product' => (int) $product->id === $id,
            'brand' => (int) $product->brand_id === $id,
            'category' => (int) $product->category_id === $id,
            default => false,
        };
    }
}
