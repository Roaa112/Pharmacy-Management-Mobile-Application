<?php

namespace Tests\Feature\Api;

use App\Models\Favorite;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class FavoritesAndReviewsApiTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    public function test_favorite_toggles_on_and_off(): void
    {
        $product = $this->makeSize()->product;

        $this->postJson("/api/user/favorites/{$product->id}")->assertOk()->assertJsonPath('message', 'Added to favorites');
        $this->getJson('/api/user/favorites')
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.is_favorite', true);

        $this->postJson("/api/user/favorites/{$product->id}")->assertOk()->assertJsonPath('message', 'Removed from favorites');
        $this->getJson('/api/user/favorites')->assertJsonPath('count', 0);
    }

    public function test_unknown_or_deleted_products_cannot_be_favorited(): void
    {
        $product = $this->makeSize()->product;
        $product->delete();

        $this->postJson('/api/user/favorites/999999')->assertNotFound();
        $this->postJson("/api/user/favorites/{$product->id}")->assertNotFound();
        $this->postJson('/api/user/favorites/abc')->assertNotFound();

        $this->assertSame(0, Favorite::count());
    }

    public function test_favorites_list_only_shows_the_callers_products(): void
    {
        $mine = $this->makeSize()->product;
        $theirs = $this->makeSize()->product;
        Favorite::create(['user_id' => User::factory()->create()->id, 'product_id' => $theirs->id]);

        $this->postJson("/api/user/favorites/{$mine->id}")->assertOk();

        $this->assertSame([$mine->id], array_column($this->getJson('/api/user/favorites')->json('data'), 'id'));
    }

    public function test_favorites_list_hides_products_deleted_later(): void
    {
        $product = $this->makeSize()->product;
        $this->postJson("/api/user/favorites/{$product->id}")->assertOk();
        $product->delete();

        $this->getJson('/api/user/favorites')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_favorites_list_query_count_does_not_grow_with_favorites(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/user/favorites')->assertOk();

            return count(DB::getQueryLog());
        };

        $this->postJson('/api/user/favorites/' . $this->makeSize()->product_id);
        $count(); // the first request also warms the per-request discount cache
        $one = $count();

        foreach (range(1, 4) as $i) {
            $this->postJson('/api/user/favorites/' . $this->makeSize()->product_id);
        }

        $this->assertSame($one, $count());
    }

    public function test_review_without_text_is_saved(): void
    {
        $product = $this->makeSize()->product;

        $this->postJson("/api/user/products/{$product->id}/rate", ['rate' => 4])
            ->assertOk()
            ->assertJsonPath('data.rate', 4);

        $this->assertDatabaseHas('product_reviews', ['product_id' => $product->id, 'user_id' => $this->customer->id, 'rate' => 4, 'review' => null]);
    }

    public function test_reviewing_again_updates_the_review_and_the_average(): void
    {
        $product = $this->makeSize()->product;
        ProductReview::create(['product_id' => $product->id, 'user_id' => User::factory()->create()->id, 'rate' => 2]);

        $this->postJson("/api/user/products/{$product->id}/rate", ['rate' => 5, 'review' => 'Great'])->assertOk();
        $this->postJson("/api/user/products/{$product->id}/rate", ['rate' => 4, 'review' => 'Good'])->assertOk();

        $this->assertSame(1, ProductReview::where('user_id', $this->customer->id)->count());
        $this->assertEquals(3.0, $product->fresh()->rate);

        $reviews = $this->getJson("/api/user/products/{$product->id}/reviews")->assertOk()->json('data');
        $this->assertCount(2, $reviews);
        $this->assertSame(['id', 'name'], array_keys(collect($reviews)->firstWhere('user_id', $this->customer->id)['user']));
    }

    public function test_review_input_is_validated(): void
    {
        $product = $this->makeSize()->product;

        $this->postJson("/api/user/products/{$product->id}/rate", ['rate' => 6])->assertStatus(422);
        $this->postJson("/api/user/products/{$product->id}/rate", ['rate' => 0])->assertStatus(422);
        $this->postJson("/api/user/products/{$product->id}/rate", ['rate' => 3, 'review' => str_repeat('a', 1001)])->assertStatus(422);
        $this->postJson('/api/user/products/999999/rate', ['rate' => 3])->assertNotFound();

        $this->assertSame(0, ProductReview::count());
    }
}
