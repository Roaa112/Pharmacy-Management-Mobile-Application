<?php

namespace Tests\Feature\Inventory;

use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryIntegrity;
use App\Modules\Inventory\Services\StockCountService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/** Final audit: inventory documents decided by one admin cannot be undone by a stale screen of another. */
class InventoryAuditTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public function test_a_completed_stock_count_cannot_be_canceled_or_changed_from_a_stale_screen(): void
    {
        $size = $this->makeSize(price: 10, stock: 10);
        $counts = app(StockCountService::class);
        $count = $counts->record($counts->start(Warehouse::default()->id, null, null), [$size->id => 7]);
        $stale = StockCount::findOrFail($count->id); // another admin's page, loaded while it was a draft

        $counts->complete($count, null);

        foreach ([fn () => $counts->cancel($stale), fn () => $counts->record($stale, [$size->id => 3])] as $action) {
            try {
                $action();
                $this->fail('A completed count was changed.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(StockCount::COMPLETED, $count->fresh()->status, 'the count that corrected the stock stays completed');
        $this->assertSame(7, $count->fresh()->items()->sole()->counted_quantity);
        $this->assertSame([7, 1], [$size->fresh()->stock, StockMovement::where('type', StockMovement::TYPE_COUNT_CORRECTION)->count()]);
        $this->assertSame([], app(InventoryIntegrity::class)->problems());
    }
}
