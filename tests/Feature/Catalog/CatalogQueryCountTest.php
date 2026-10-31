<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class CatalogQueryCountTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private function queriesFor(string $uri): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_product_listing_queries_do_not_grow_with_the_number_of_products(): void
    {
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 3; $i++) {
            $this->makeSize();
        }
        $few = $this->queriesFor('/api/v1/user/products');

        for ($i = 0; $i < 12; $i++) {
            $this->makeSize();
        }
        $many = $this->queriesFor('/api/v1/user/products');

        fwrite(STDERR, "\n[catalog] 3 products: {$few} queries, 15 products: {$many} queries\n");

        // The first request also resolves the authenticated user, hence <= rather than ===.
        $this->assertLessThanOrEqual($few, $many, 'Query count must not depend on the number of products (N+1).');
    }
}
