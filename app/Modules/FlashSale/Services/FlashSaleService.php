<?php

namespace App\Modules\FlashSale\Services;

use App\Models\AdminAuditLog;
use App\Models\FlashSale;
use App\Models\FlashSaleItem;
use App\Models\Product;
use App\Models\ProductSize;
use App\Modules\FlashSale\Resources\FlashSaleCollection;
use App\Modules\FlashSale\Repositories\FlashSalesRepository;
use App\Modules\FlashSale\Requests\ListAllFlashSalesRequest;
use Illuminate\Support\Facades\DB;

class FlashSaleService
{
    public function __construct(private FlashSalesRepository $flashSalesRepository)
    {
    }

    public function createFlashSale($request)
    {
        return DB::transaction(function () use ($request) {
            $sale = FlashSale::create($this->constructFlashSaleModel($request));
            $this->syncItems($sale, $request);
            AdminAuditLog::record('flash_sale.created', $sale, $sale->toArray());

            return $sale;
        });
    }

    public function updateFlashSale($id, $request)
    {
        return DB::transaction(function () use ($id, $request) {
            $sale = FlashSale::findOrFail($id);
            $before = $sale->toArray();
            $data = $this->constructFlashSaleModel($request);
            if (empty($request['ends_at'])) {
                unset($data['ends_at']); // keep the saved end
            }
            $sale->update($data);
            $this->syncItems($sale, $request);
            AdminAuditLog::record('flash_sale.updated', $sale, ['before' => $before, 'after' => $sale->fresh()->toArray()]);

            return $sale;
        });
    }

    public function deleteFlashSale($id)
    {
        return DB::transaction(function () use ($id) {
            $sale = FlashSale::findOrFail($id);
            Product::where('saleable_type', FlashSale::class)->where('saleable_id', $sale->id)->update(['saleable_type' => null, 'saleable_id' => null]);
            AdminAuditLog::record('flash_sale.deleted', $sale, $sale->toArray());

            return $sale->delete();
        });
    }

    public function listAllFlashSales(array $queryParameters)
    {

        $listAllFlashSales= (new ListAllFlashSalesRequest)->constructQueryCriteria($queryParameters);
        $flashSales= $this->flashSalesRepository->findAllBy($listAllFlashSales );

        return [
            'data' => new FlashSaleCollection($flashSales['data']),
            'count' => $flashSales['count']
        ];
    }

    public function getFlashSaleById($id)
    {
        return $this->flashSalesRepository->find($id);
    }

    public function constructFlashSaleModel($request)
    {
        return [
            'name_en' => $request['name_en'] ?? null,
            'name_ar' => $request['name_ar'] ?? null,
            'discount_type' => $request['discount_type'] ?? FlashSale::TYPE_PERCENT,
            'discount_value' => $request['discount_value'],
            'time' => $request['time'],
            'is_active' => $request['is_active'],
            'date' => $request['date'],
            'ends_at' => $request['ends_at'] ?? null,
            'priority' => $request['priority'] ?? 0,
            'quantity_limit' => $request['quantity_limit'] ?? null,
            'per_customer_limit' => $request['per_customer_limit'] ?? null,
        ];
    }

    /**
     * Products (every size) and single variants in the sale. Sent lists replace the items; a
     * product removed here also loses the product form's link to this sale.
     */
    private function syncItems(FlashSale $sale, $request): void
    {
        if (empty($request['sync_items']) && !array_key_exists('product_ids', $request) && !array_key_exists('size_ids', $request)) {
            return;
        }

        $products = array_map('intval', $request['product_ids'] ?? []);
        $sizes = ProductSize::whereIn('id', $request['size_ids'] ?? [])->get(['id', 'product_id']);

        $keep = [];
        foreach ($products as $productId) {
            $keep[] = FlashSaleItem::firstOrCreate(['flash_sale_id' => $sale->id, 'product_id' => $productId, 'product_size_id' => null])->id;
        }
        foreach ($sizes as $size) {
            $keep[] = FlashSaleItem::firstOrCreate(['flash_sale_id' => $sale->id, 'product_id' => $size->product_id, 'product_size_id' => $size->id])->id;
        }

        $removed = $sale->items()->whereNotIn('id', $keep ?: [0])->get();
        Product::where('saleable_type', FlashSale::class)->where('saleable_id', $sale->id)
            ->whereIn('id', $removed->whereNull('product_size_id')->pluck('product_id'))
            ->update(['saleable_type' => null, 'saleable_id' => null]);
        $sale->items()->whereNotIn('id', $keep ?: [0])->delete();
    }
}
