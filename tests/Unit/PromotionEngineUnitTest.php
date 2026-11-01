<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductSize;
use App\Modules\Promotions\Engine\ConflictResolver;
use App\Modules\Promotions\Engine\Strategies\ItemCandidate;
use App\Modules\Promotions\Engine\TargetMatcher;
use Tests\TestCase;

class PromotionEngineUnitTest extends TestCase
{
    private function candidate(string $source, int $id, int $rank, int $priority, int $specificity, bool $stackable): ItemCandidate
    {
        return new ItemCandidate($source, $id, 'percent', "#{$id}", $rank, $priority, $specificity, $stackable, fn (int $u) => $u);
    }

    public function test_item_resolution_is_a_total_order_independent_of_input_order(): void
    {
        $candidates = [
            $this->candidate('promotion', 7, 0, 0, 1, false),  // category, old
            $this->candidate('promotion', 9, 0, 0, 3, false),  // product
            $this->candidate('promotion', 3, 0, 2, 1, false),  // category, higher priority
            $this->candidate('flash_sale', 50, 1, 0, 3, false),
        ];

        foreach ([$candidates, array_reverse($candidates)] as $order) {
            [$applied, $rejected] = (new ConflictResolver())->resolveItem($order);
            $this->assertSame([50], array_map(fn ($c) => $c->id, $applied));
            $this->assertSame([3, 9, 7], array_map(fn ($r) => $r[0]->id, $rejected));
        }
    }

    public function test_only_stackable_candidates_stack_on_a_stackable_winner(): void
    {
        [$applied, $rejected] = (new ConflictResolver())->resolveItem([
            $this->candidate('promotion', 1, 0, 5, 3, true),
            $this->candidate('promotion', 2, 0, 4, 3, true),
            $this->candidate('promotion', 3, 0, 3, 3, false),
        ]);

        $this->assertSame([1, 2], array_map(fn ($c) => $c->id, $applied));
        $this->assertSame('not_stackable', $rejected[0][1]);
    }

    public function test_automatic_discounts_keep_the_first_unless_both_stack_and_delivery_picks_the_largest(): void
    {
        $resolver = new ConflictResolver();

        $this->assertSame([['a'], ['b']], $resolver->resolveAutomaticDiscounts([['key' => 'a', 'amount' => 10, 'stackable' => false], ['key' => 'b', 'amount' => 50, 'stackable' => true]]));
        $this->assertSame([['a', 'b'], []], $resolver->resolveAutomaticDiscounts([['key' => 'a', 'amount' => 10, 'stackable' => true], ['key' => 'b', 'amount' => 50, 'stackable' => true]]));
        $this->assertTrue($resolver->couponBeatsAutomatic(100, 100), 'Tie: the coupon the customer chose');
        $this->assertSame('2', $resolver->resolveDelivery([['key' => '1', 'amount' => 20], ['key' => '2', 'amount' => 40], ['key' => '3', 'amount' => 40]]));
    }

    public function test_targets_match_by_specificity_with_exclusions(): void
    {
        $product = (new Product())->forceFill(['id' => 5, 'brand_id' => 7, 'category_id' => 9]);
        $size = (new ProductSize())->forceFill(['id' => 11, 'product_id' => 5]);
        $t = fn ($type, $id, $excluded = false) => (object) ['target_type' => $type, 'target_id' => $id, 'is_excluded' => $excluded, 'is_gift' => false];

        $this->assertSame(1, TargetMatcher::specificity([$t('category', 9)], $product, $size, false));
        $this->assertSame(4, TargetMatcher::specificity([$t('category', 9), $t('variant', 11)], $product, $size, false));
        $this->assertNull(TargetMatcher::specificity([$t('category', 9), $t('product', 5, true)], $product, $size, false));
        $this->assertNull(TargetMatcher::specificity([], $product, $size, false));
        $this->assertSame(0, TargetMatcher::specificity([], $product, $size, true));
        $this->assertNull(TargetMatcher::specificity([$t('brand', 8)], $product, $size, true));
    }
}
