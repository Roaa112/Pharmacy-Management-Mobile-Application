<?php

namespace App\Modules\Product\Repositories;

use App\Models\Product;
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

            // تأكد إن الملف المؤقت موجود فعلاً
            if (!file_exists($tmpPath)) {
                continue;
            }

            $filename = uniqid() . '.' . $image->getClientOriginalExtension();

            // نستخدم copy بدل move لو حابب تتأكد أكتر من صلاحية الصورة
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
        $query = Product::with(['brand', 'category', 'healthIssues','productImages','sizes']);

        $products = $query->get();

        return [
            'data' => $products,
            'count' => $products->count(),
        ];
    }


    public function syncHealthIssues(Product $product, array $healthIssues): void
    {
        $product->healthIssues()->sync($healthIssues);
    }

    public function replaceSizes(Product $product, array $sizes): void
    {
        $product->sizes()->delete(); // clear old
        foreach ($sizes as $sizeData) {
            $product->sizes()->create($sizeData);
        }
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
            return collect(); // لا يوجد خصومات مفعلة
        }

        return Product::with(['saleable', 'brand', 'category', 'healthIssues', 'productImages'])
            ->where('saleable_id', $topDiscount->id)
            ->where('saleable_type', \App\Models\Discount::class)
            ->get();
    }

    public function executeGetMany($query, $queryCriteria = [])
    {
        // تحميل العلاقات المطلوبة
        if (method_exists($query, 'with')) {
            $query = $query->with(['productImages', 'brand', 'category', 'healthIssues', 'saleable']);
        }

        // فلترة المنتجات اللي عليها خصم
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
                $q->where('name_ar', 'like', '%' . $searchTerm . '%')  // البحث في الاسم العربي
                ->orWhere('name_en', 'like', '%' . $searchTerm . '%')  // البحث في الاسم الإنجليزي
                ->orWhere('description_ar', 'like', '%' . $searchTerm . '%')  // البحث في الوصف العربي
                ->orWhere('description_en', 'like', '%' . $searchTerm . '%')  // البحث في الوصف الإنجليزي
                ->orWhereHas('brand', function ($brandQuery) use ($searchTerm) {
                    $brandQuery->where('name_ar', 'like', '%' . $searchTerm . '%')  // البحث في الاسم العربي للعلامة التجارية
                                ->orWhere('name_en', 'like', '%' . $searchTerm . '%');  // البحث في الاسم الإنجليزي للعلامة التجارية
                })
                ->orWhereHas('category', function ($categoryQuery) use ($searchTerm) {
                    $categoryQuery->where('name_ar', 'like', '%' . $searchTerm . '%')  // البحث في الاسم العربي للفئة
                                    ->orWhere('name_en', 'like', '%' . $searchTerm . '%');  // البحث في الاسم الإنجليزي للفئة
                });
            });
        }



        // تطبيق الفلاتر
        if (!empty($queryCriteria['filters'])) {
            foreach ($queryCriteria['filters'] as $column => $condition) {
                $operator = $condition['operator'] ?? '=';
                $value = $condition['value'] ?? null;

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

        // الترتيب
        if (!empty($queryCriteria['sortBy'])) {
            $sort = $queryCriteria['sort'] ?? 'asc';
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
