<?php

namespace Tests\Feature\Api;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\HealthIssue;
use App\Models\OpeningAd;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Category $category;
    private Brand $brand;
    private HealthIssue $issue;
    private Product $product;
    private Product $sibling;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name_en' => 'Vitamins', 'name_ar' => 'فيتامينات', 'image' => 'categories/a.png']);
        $this->brand = Brand::create(['name_en' => 'Acme', 'name_ar' => 'أكمي', 'image' => 'brands/a.png']);
        $this->issue = HealthIssue::create(['name_en' => 'Immunity', 'name_ar' => 'مناعة', 'image' => 'issues/a.png']);

        $this->product = $this->makeSize(price: 30)->product;
        $this->product->update(['category_id' => $this->category->id, 'brand_id' => $this->brand->id, 'name_en' => 'Vitamin C']);
        $this->sibling = $this->makeSize(price: 20)->product;
        $this->sibling->update(['category_id' => $this->category->id, 'brand_id' => $this->brand->id, 'name_en' => 'Zinc']);
        $this->product->healthIssues()->attach($this->issue->id);

        Banner::create(['title_en' => 'Sale', 'title_ar' => 'تخفيض', 'image' => 'banners/a.png', 'status' => 1, 'order_of_appearance' => 1]);
        OpeningAd::create(['image' => 'ads/a.png', 'is_active' => true]);
        Setting::create(['points_validity_days' => 30]);
        $this->makeDeliveryPrice('Baghdad', 5);

        // One sale so the best-selling endpoints have data.
        $this->makeOrder(User::factory()->create(), $this->product->sizes()->first(), quantity: 3);
    }

    private function catalogUrls(): array
    {
        $c = $this->category->id;
        $b = $this->brand->id;
        $h = $this->issue->id;
        $p = $this->product->id;

        return [
            'brands', "brands/{$b}/products", "brands/{$b}/best-selling-products", "brands/{$b}/new-arrival-products",
            'categories', "categories/{$c}/products", "categories/{$c}/products/search?search=vit",
            "categories/{$c}/best-selling-products", "categories/{$c}/new-arrival-products",
            'health_issues', "health_issues/{$h}/products", "healthissues/{$h}/best-selling-products", "healthissues/{$h}/new-arrival-products",
            'products', "show/{$p}/product", 'products/on/sale', 'products/search?search=vit', "products/{$p}/related",
            'latest-products', 'top-discount-products', 'Best-selling-products',
            'banners', 'openingAd', 'settings', 'delivary_prices',
        ];
    }

    public function test_every_public_catalog_endpoint_answers_on_both_prefixes(): void
    {
        foreach (['/api/v1/user/', '/api/v1/v1/user/'] as $prefix) {
            foreach ($this->catalogUrls() as $url) {
                $response = $this->getJson($prefix . $url);
                $this->assertSame(200, $response->status(), "{$prefix}{$url}: " . substr($response->getContent(), 0, 300));
            }
        }
    }

    public function test_catalog_endpoints_survive_hostile_paging_and_sorting_parameters(): void
    {
        $hostile = [
            'sortBy=does_not_exist', 'sort=sideways', 'sortBy=id;drop table users', 'limit=-5', 'offset=-5',
            'limit=abc', 'filters[name_en]=%25', 'filters=notarray', 'search[]=x',
        ];

        // Hundreds of requests: the per-minute API limit is not what this test is about.
        $this->withoutMiddleware(ThrottleRequests::class);

        $failures = [];
        foreach ($this->catalogUrls() as $url) {
            foreach ($hostile as $query) {
                $full = '/api/v1/user/' . $url . (str_contains($url, '?') ? '&' : '?') . $query;
                $status = $this->getJson($full)->status();
                if (!in_array($status, [200, 400, 422], true)) {
                    $failures[] = "{$full} answered {$status}";
                }
            }
        }

        $this->assertSame([], $failures);
        $this->assertNotNull(User::query()->first());
    }

    public function test_valid_paging_sorting_and_filters_still_apply(): void
    {
        $ids = fn (string $url) => array_column($this->getJson($url)->assertOk()->json('data'), 'id');

        $this->assertSame([$this->sibling->id], $ids('/api/v1/user/products?limit=1&sortBy=id&sort=desc'));
        $this->assertSame([$this->product->id], $ids('/api/v1/user/products?limit=1&sortBy=id&sort=asc'));
        $this->assertSame([$this->product->id], $ids('/api/v1/user/products?limit=1&offset=1&sortBy=id&sort=desc'));
        $this->assertSame([$this->product->id], $ids("/api/v1/user/categories/{$this->category->id}/products?limit=1"));

        // Filters on real columns work; unknown columns or operators are ignored, not executed.
        $this->assertSame([$this->sibling->id], $ids("/api/v1/user/products/search?search=a&filters[id][value]={$this->sibling->id}"));
        $this->assertCount(2, $ids('/api/v1/user/products/search?search=a&filters[password][value]=x'));
        $this->assertCount(2, $ids("/api/v1/user/products/search?search=a&filters[id][operator]=in&filters[id][value]=1"));
    }

    public function test_product_details_and_missing_resources(): void
    {
        $this->getJson("/api/v1/user/show/{$this->product->id}/product")
            ->assertOk()
            ->assertJsonPath('data.id', $this->product->id)
            ->assertJsonPath('data.name', 'Vitamin C');

        $this->getJson('/api/v1/user/show/999999/product')->assertNotFound();
        $this->getJson('/api/v1/user/products/999999/related')->assertNotFound();
        $this->getJson('/api/v1/user/brands/999999/products')->assertNotFound();
        $this->getJson('/api/v1/user/categories/999999/products')->assertNotFound();
        $this->getJson('/api/v1/user/categories/999999/best-selling-products')->assertNotFound();
        $this->getJson('/api/v1/user/brands/999999/new-arrival-products')->assertNotFound();
        $this->getJson('/api/v1/user/products/search')->assertStatus(400);
    }

    public function test_deleted_products_disappear_from_the_catalog(): void
    {
        $this->sibling->delete();

        $this->getJson("/api/v1/user/show/{$this->sibling->id}/product")->assertNotFound();
        $this->assertNotContains($this->sibling->id, array_column($this->getJson("/api/v1/user/products/{$this->product->id}/related")->json('data'), 'id'));
        $this->assertNotContains($this->sibling->id, array_column($this->getJson("/api/v1/user/categories/{$this->category->id}/products")->json('data'), 'id'));
        $this->assertNotContains($this->sibling->id, array_column($this->getJson("/api/v1/user/brands/{$this->brand->id}/new-arrival-products")->json('data'), 'id'));
    }

    public function test_search_and_related(): void
    {
        // "Vitamin" also matches the category name, so search for a product-only word.
        $found = $this->getJson('/api/v1/user/products/search?search=Zinc')->assertOk()->json('data');
        $this->assertSame([$this->sibling->id], array_column($found, 'id'));

        $related = $this->getJson("/api/v1/user/products/{$this->product->id}/related")->assertOk()->json('data');
        $this->assertSame([$this->sibling->id], array_column($related, 'id'));
    }

    public function test_best_selling_counts_quantities_and_can_be_sorted(): void
    {
        $this->getJson('/api/v1/user/Best-selling-products')
            ->assertOk()
            ->assertJsonPath('data.0.total_sold', 3)
            ->assertJsonPath('data.0.product.id', $this->product->id);

        foreach (['total_sold', 'product_id', 'id'] as $sortBy) {
            $this->getJson("/api/v1/user/Best-selling-products?sortBy={$sortBy}&sort=asc")->assertOk();
        }

        $this->getJson("/api/v1/user/categories/{$this->category->id}/best-selling-products")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->product->id)
            ->assertJsonPath('data.0.total_sold', 3);
    }

    public function test_reviews_list_is_public_and_404s_for_unknown_products(): void
    {
        $this->getJson("/api/user/products/{$this->product->id}/reviews")->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/user/products/999999/reviews')->assertNotFound();
    }
}
