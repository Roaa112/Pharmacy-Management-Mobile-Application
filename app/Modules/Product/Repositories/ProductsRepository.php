<?php

namespace App\Modules\Product\Repositories;

use App\Models\Product;
use App\Models\OrderItem;
use App\Models\ProductSize;
use App\Models\StockMovement;
use App\Support\TableColumns;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Modules\Shared\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Model;

class ProductsRepository extends BaseRepository
{
    public function __construct(private Product $model)
    {
        parent::__construct($model);
    }
    public function removeExtraImages(Product $product, array $imageIds): void
    {

        foreach ($imageIds as $id) {
            $img = $product->productImages()->find($id);
            if ($img && file_exists(public_path($img->image_path))) {
                @unlink(public_path($img->image_path));
                $img->delete();
            }
        }
    }

    public function addExtraImages(Product $product, array $images)
    {

        foreach ($images as $image) {
            if (!$image || !$image->isValid()) {
                continue;
            }

            $tmpPath = $image->getPathname();

            //  إن الملف المؤقت وجود فعلاً
            if (!file_exists($tmpPath)) {
                continue;
            }

            $filename = uniqid() . '.' . $image->getClientOriginalExtension();

            // نستخدم copy د move لو حابب تتأد أكتر من صلاحية لصوة
            $image->move(public_path('products'), $filename);

            $product->productImages()->create([
                'image_path' => 'products/' . $filename,
            ]);
        }
    }


    public function update($id, array $data): ?Product
    {
        $product = $this->model->find($id);

        if (!$product) {
            return null;
        }

        $product->update($data);
        return $product;
    }
    public function create($attributes): Model
    {

        unset($attributes['images'], $attributes['health_issues']);


        return $this->model->create($attributes);
    }
    public function findAllBy($queryCriteria = [])
    {
        $query = Product::with(['brand', 'category', 'healthIssues', 'productImages', 'sizes']);

        // ✅ تطبيق الفلاتر
        if (!empty($queryCriteria['filters'])) {
            foreach ($queryCriteria['filters'] as $filter) {
                $query->where(
                    $filter['column'],
                    $filter['operator'],
                    $filter['value']
                );
            }
        }

        // ✅ تطبيق الترتيب
        $sortBy = TableColumns::orDefault('products', $queryCriteria['sortBy'] ?? 'id');
        $sort = strtolower((string) ($queryCriteria['sort'] ?? 'DESC')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sort);

        // ✅ حساب العدد الكلي قبل limit
        $totalCount = $query->count();

        // ✅ تطبيق limit و offset
        if (isset($queryCriteria['limit'])) {
            $limit = $queryCriteria['limit'];
            $offset = $queryCriteria['offset'] ?? 0;

            $query->offset($offset)->limit($limit);
            $products = $query->get();
        } else {
            $products = $query->get();
        }

        return [
            'data' => $products,
            'count' => $totalCount,
        ];
    }


    public function syncHealthIssues(Product $product, array $healthIssues): void
    {
        $product->healthIssues()->sync($healthIssues);
    }

    /**
     * Sync the product's sizes with the submitted list.
     *
     * Sizes are updated in place instead of being deleted and recreated: deleting a size
     * cascades to order_items and carts, which used to wipe order history on every product edit.
     */
    public function replaceSizes(Product $product, array $sizes): void
    {
        $context = ['admin_id' => auth('admin')->id()];

        DB::transaction(function () use ($product, $sizes, $context) {
            $keptIds = [];

            foreach ($sizes as $sizeData) {
                if (!isset($sizeData['size'], $sizeData['price'])) {
                    continue; // empty form row
                }

                $size = !empty($sizeData['id']) ? $product->sizes()->find($sizeData['id']) : null;
                $submittedStock = (int) ($sizeData['stock'] ?? 0);

                if ($size) {
                    $size->update(['size' => $sizeData['size'], 'price' => $sizeData['price']]);
                    $this->applyStockEdit($size, $submittedStock, $sizeData['stock_original'] ?? null, $context);
                } else {
                    $size = $product->sizes()->create(['size' => $sizeData['size'], 'price' => $sizeData['price'], 'stock' => 0]);
                    ProductSize::setStock($size->id, $submittedStock, StockMovement::TYPE_INITIAL, $context + ['reason' => 'Size added']);
                }

                $keptIds[] = $size->id;
            }

            $removed = $product->sizes()->whereNotIn('id', $keptIds)->get();

            foreach ($removed as $size) {
                if (OrderItem::where('product_size_id', $size->id)->exists()) {
                    // Sold before: keep the row for order history, just take it off sale.
                    ProductSize::setStock($size->id, 0, StockMovement::TYPE_ADJUSTMENT, $context + ['reason' => 'Size removed from product']);
                } else {
                    $size->delete();
                }
            }
        });
    }

    /**
     * The edit form shows the stock as it was when the page loaded ("stock_original").
     * - Unchanged field: leave stock alone, so sales made meanwhile are not overwritten.
     * - Changed field: apply the admin's difference to the current stock (e.g. +50 received).
     * Without stock_original (older form), the submitted value is taken as the new count.
     */
    private function applyStockEdit(ProductSize $size, int $submittedStock, $originalStock, array $context): void
    {
        if ($originalStock === null || $originalStock === '') {
            ProductSize::setStock($size->id, $submittedStock, StockMovement::TYPE_ADJUSTMENT, $context + ['reason' => 'Stock set by admin']);
            return;
        }

        $difference = $submittedStock - (int) $originalStock;

        if ($difference === 0) {
            return;
        }

        $current = (int) ProductSize::whereKey($size->id)->value('stock');

        if ($current + $difference < 0) {
            throw ValidationException::withMessages([
                'sizes' => "Stock for size {$size->size} changed while you were editing (now {$current}). Reload the page and try again.",
            ]);
        }

        ProductSize::moveStock($size->id, $difference, StockMovement::TYPE_ADJUSTMENT, $context + [
            'reason' => "Admin changed stock from {$originalStock} to {$submittedStock}",
        ]);
    }

    public function getLatestProducts(int $limit = 15)
    {
        return Product::with(['saleable', 'brand', 'category', 'healthIssues', 'productImages'])
            ->latest()
            ->take($limit)
            ->get();
    }
    public function getProductsOfTopDiscount()
    {
        $topDiscount = \App\Models\Discount::where('is_active', true)
            ->orderByDesc('precentage')
            ->first();

        if (!$topDiscount) {
            return collect(); // لا يوجد خوما فعلة
        }

        return Product::with(['saleable', 'brand', 'category', 'healthIssues', 'productImages'])
            ->where('saleable_id', $topDiscount->id)
            ->where('saleable_type', \App\Models\Discount::class)
            ->get();
    }

    public function executeGetMany($query, $queryCriteria = [])
    {
        // تحيل العلاقات لمطلبة
        if (method_exists($query, 'with')) {
            $query = $query->with(['productImages', 'brand', 'category', 'healthIssues', 'saleable']);
        }

        // فلترة النتجات اللي ليها خصم
        if (!empty($queryCriteria['has_discount'])) {
            $query->whereHasMorph(
                'saleable',
                [\App\Models\Discount::class, \App\Models\FlashSale::class],
                function ($q) {
                    $q->where('is_active', true);
                }
            );
        }
        if (!empty($queryCriteria['search'])) {
            $searchTerm = $queryCriteria['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name_ar', 'like', '%' . $searchTerm . '%')  // البحث في الاسم اعرب
                    ->orWhere('name_en', 'like', '%' . $searchTerm . '%')  // البحث ف الاسم الإجليزي
                    ->orWhere('description_ar', 'like', '%' . $searchTerm . '%')  // ابحث في اصف اعربي
                    ->orWhere('description_en', 'like', '%' . $searchTerm . '%')  // الحث في الوصف الجليزي
                    ->orWhereHas('brand', function ($brandQuery) use ($searchTerm) {
                        $brandQuery->where('name_ar', 'like', '%' . $searchTerm . '%')  // البحث ي الاسم لعربي للعامة التارية
                            ->orWhere('name_en', 'like', '%' . $searchTerm . '%');  // البح ي ااسم الإنجليز للعامة التجاري
                    })
                    ->orWhereHas('category', function ($categoryQuery) use ($searchTerm) {
                        $categoryQuery->where('name_ar', 'like', '%' . $searchTerm . '%')  // الحث في لاسم العربي للفئ
                            ->orWhere('name_en', 'like', '%' . $searchTerm . '%');  // البحث في الام الإنجلزي للفئة
                    });
            });
        }



        // تطبيق لفلات
        if (!empty($queryCriteria['filters']) && is_array($queryCriteria['filters'])) {
            foreach ($queryCriteria['filters'] as $column => $condition) {
                // Filter keys and operators come from the query string: only real product
                // columns and plain comparisons are allowed.
                if (!TableColumns::has('products', $column) || !is_array($condition)) {
                    continue;
                }

                $operator = $condition['operator'] ?? '=';
                $value = $condition['value'] ?? null;

                if (!in_array($operator, ['=', '!=', 'like', '>', '>=', '<', '<='], true) || is_array($value)) {
                    continue;
                }

                if ($operator === 'like') {
                    $query->where($column, 'like', $value);
                } elseif ($operator === '>=') {
                    $query->where($column, '>=', $value);
                } elseif ($operator === '<=') {
                    $query->where($column, '<=', $value);
                } else {
                    $query->where($column, $operator, $value);
                }
            }
        }

        // لترتيب
        if (!empty($queryCriteria['sortBy']) && TableColumns::has('products', $queryCriteria['sortBy'])) {
            $sort = strtolower((string) ($queryCriteria['sort'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
            $query->orderBy($queryCriteria['sortBy'], $sort);
        }

        // باجينج
        $limit = $queryCriteria['limit'] ?? 10;
        $offset = $queryCriteria['offset'] ?? 0;

        $data = $query->offset($offset)->limit($limit)->get();
        $count = $query->toBase()->getCountForPagination();

        return [
            'data' => $data,
            'count' => $count,
        ];
    }
}
