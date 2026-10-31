<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\HealthIssue;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every public catalog endpoint must keep answering after the Product model changes
 * (eager loading, aggregates, soft deletes). On MariaDB this also exercises strict SQL mode.
 */
class CatalogSmokeTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Product $product;
    private Category $category;
    private Brand $brand;
    private HealthIssue $healthIssue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name_ar' => 'فئة', 'name_en' => 'Category', 'image' => 'category/c.png']);
        $this->brand = Brand::create(['name_ar' => 'ماركة', 'name_en' => 'Brand', 'image' => 'brands/b.png']);
        $this->healthIssue = HealthIssue::create(['name_ar' => 'مشكلة', 'name_en' => 'Issue', 'image' => 'healthissue/h.png']);

        $size = $this->makeSize(price: 40, stock: 5);
        $this->product = $size->product;
        $this->product->update(['category_id' => $this->category->id, 'brand_id' => $this->brand->id, 'name_en' => 'Vitamin C']);
        $this->product->healthIssues()->attach($this->healthIssue->id);

        $ruleId = DB::table('discount_rules')->insertGetId([
            'discount_type' => 'percent', 'discount_value' => 10, 'applies_to_type' => 'brand',
            'applies_to_id' => $this->brand->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
        ]);
        DB::table('discount_rule_targets')->insert(['discount_rule_id' => $ruleId, 'target_type' => 'brand', 'target_id' => $this->brand->id]);

        $customer = User::factory()->create();
        $this->makeOrder($customer, $size, 2);
        Sanctum::actingAs($customer);
    }

    public static function endpoints(): array
    {
        return [
            ['/api/v1/user/products'],
            ['/api/v1/user/products/on/sale'],
            ['/api/v1/user/products/search?search=Vitamin'],
            ['/api/v1/user/latest-products'],
            ['/api/v1/user/top-discount-products'],
            ['/api/v1/user/Best-selling-products'],
            ['/api/v1/user/show/{product}/product'],
            ['/api/v1/user/products/{product}/related'],
            ['/api/v1/user/categories'],
            ['/api/v1/user/categories/{category}/products'],
            ['/api/v1/user/categories/{category}/products/search?search=Vitamin'],
            ['/api/v1/user/categories/{category}/best-selling-products'],
            ['/api/v1/user/categories/{category}/new-arrival-products'],
            ['/api/v1/user/brands'],
            ['/api/v1/user/brands/{brand}/products'],
            ['/api/v1/user/brands/{brand}/best-selling-products'],
            ['/api/v1/user/brands/{brand}/new-arrival-products'],
            ['/api/v1/user/health_issues'],
            ['/api/v1/user/health_issues/{issue}/products'],
            ['/api/v1/user/healthissues/{issue}/best-selling-products'],
            ['/api/v1/user/healthissues/{issue}/new-arrival-products'],
            ['/api/user/favorites'],
            ['/api/user/products/{product}/reviews'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_catalog_endpoint_responds(string $uri): void
    {
        $uri = strtr($uri, [
            '{product}' => $this->product->id,
            '{category}' => $this->category->id,
            '{brand}' => $this->brand->id,
            '{issue}' => $this->healthIssue->id,
        ]);

        $this->getJson($uri)->assertOk();
    }

    public function test_product_payload_keeps_its_shape_and_prices(): void
    {
        $product = $this->getJson("/api/v1/user/show/{$this->product->id}/product")->assertOk()->json('data');

        foreach (['name', 'description', 'is_favorite', 'image_url', 'images', 'average_rating', 'ratings_count', 'available_sizes', 'final_price', 'discount_type', 'sizes'] as $key) {
            $this->assertArrayHasKey($key, $product);
        }
        foreach (['reviews_count', 'reviews_avg_rate', 'favorited_by_current_user', 'deleted_at'] as $internal) {
            $this->assertArrayNotHasKey($internal, $product);
        }

        $this->assertSame('percent', $product['discount_type']);
        $this->assertEquals(36, $product['final_price']);
        $this->assertEquals(36, $product['available_sizes'][0]['price']);
        $this->assertFalse($product['is_favorite']);
        $this->assertSame(0, $product['ratings_count']);
    }
}
