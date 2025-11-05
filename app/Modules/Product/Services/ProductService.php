<?php

namespace App\Modules\Product\Services;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;


use App\Modules\Product\Resources\ProductCollection;
use App\Modules\Product\Requests\UpdateProductRequest;
use App\Modules\Product\Repositories\ProductsRepository;
use App\Modules\Product\Requests\ListAllProductsRequest;

class ProductService
{
    public function __construct(private ProductsRepository $productsRepository) {}
    private function handleMainImage(array &$data, $request, Product $product = null): void
    {

        if ($request->hasFile('image')) {
            $image = $request->file('image');

            if (!$image->isValid()) {
                return;
            }

            // انسخ ال ؤقتًا قبل أي ميت (نتجنب فقدان لمف امؤقت)
            $tmpPath = $image->getPathname();
            $extension = $image->getClientOriginalExtension();

            $filename = uniqid() . '.' . $extension;
            $destinationPath = public_path('products');

            // حف الصورة اقديمة لو فه منتج
            if ($product && $product->image && file_exists(public_path($product->image))) {
                try {
                    unlink(public_path($product->image));
                } catch (\Exception $e) {
                    // Log::error("Error deleting image: " . $e->getMessage());
                }
            }

            // انخ الصرة بكل يدوي لو اللف لمؤت موجود
            if (file_exists($tmpPath)) {
                copy($tmpPath, $destinationPath . '/' . $filename);
                $data['image'] = 'products/' . $filename;
            }
        } elseif ($product) {
            $data['image'] = $product->image;
        }
    }
    public function updateProduct(Product $product, UpdateProductRequest $request): ?Product
    {
        $data = $request->validated();

        // معالجة بياات العوض (Promotion)
        $this->handlePromotionData($data, $request, $product);

        // مالجة الصوة الرئيية
        $this->handleMainImage($data, $request, $product);

        // تحديث امج ي لـ Repository
        $product = $this->productsRepository->update($product->id, $data);
        if (!$product) return null;

        // حذف اصر اإضافية (لو طلبة)
        if ($request->has('remove_extra_images')) {
            $this->productsRepository->removeExtraImages($product, $request->remove_extra_images);
        }

        // إضافة ور إضفية
        $images = $request->file('images');
        if (is_array($images)) {
            $validImages = array_filter($images, function ($img) {
                return $img && $img->isValid();
            });

            if (!empty($validImages)) {
                $this->productsRepository->addExtraImages($product, $validImages);
            }
        }

        // رب امشاكل لصحي بالمنج
        $this->productsRepository->syncHealthIssues($product, $data['health_issues'] ?? []);

        // تحديث لمقاات (أحجام)
        if ($request->filled('sizes')) {
            $this->productsRepository->replaceSizes($product, $data['sizes']);
        }

        return $product;
    }
    private function handleMultipleImages(Product $product, $request): void
    {

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                if (!$image || !$image->isValid()) {
                    continue;
                }

                $tmpPath = $image->getPathname();

                if (!file_exists($tmpPath)) {
                    continue;
                }

                $filename = uniqid() . '.' . $image->getClientOriginalExtension();
                $image->move(public_path('products'), $filename);

                $product->productImages()->create([
                    'image_path' => 'products/' . $filename,
                ]);
            }
        }
    }


    private function handlePromotionData(array &$data, $request, Product $product = null): void
    {
        if ($request->filled('promotion_type')) {
            $data['promotion_type'] = $request->promotion_type;

            if ($request->promotion_type === 'discount') {
                $data['saleable_type'] = \App\Models\Discount::class;
                $data['saleable_id'] = $request->discount_id;
            } elseif ($request->promotion_type === 'flash_sale') {
                $data['saleable_type'] = \App\Models\FlashSale::class;
                $data['saleable_id'] = $request->flash_sale_id;
            } else {
                $data['saleable_type'] = null;
                $data['saleable_id'] = null;
            }
        } elseif ($product) {
            $data['promotion_type'] = $product->promotion_type;
            $data['saleable_type'] = $product->saleable_type;
            $data['saleable_id'] = $product->saleable_id;
        }
    }


    public function handleStoreProduct(array $data, $request)
    {
        $this->applyPromotionType($data, $request);

        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $filename = uniqid() . '.' . $image->getClientOriginalExtension();
            $path = $image->storeAs('products', $filename, 'public');
            $data['image'] = 'storage/' . $path;
        }


        $product = $this->createProduct($data);

        $this->handleMultipleImages($product, $request);

        if ($request->filled('health_issues')) {
            $product->healthIssues()->sync($data['health_issues']);
        }
        if ($request->filled('sizes')) {
            $sizes = collect($request->input('sizes', []))
                ->filter(function ($size) {
                    return !empty($size['size']) && !empty($size['price']) && !empty($size['stock']);
                })
                ->values()
                ->all();

            if (!empty($sizes)) {
                $this->handleSizes($product, $sizes);
            }
        }


        return $product;
    }

    private function applyPromotionType(array &$data, $request)
    {
        if ($request->filled('promotion_type')) {
            if ($request->promotion_type === 'discount' && $request->filled('discount_id')) {
                $data['saleable_type'] = \App\Models\Discount::class;
                $data['saleable_id'] = $request->discount_id;
            } elseif ($request->promotion_type === 'flash_sale' && $request->filled('flash_sale_id')) {
                $data['saleable_type'] = \App\Models\FlashSale::class;
                $data['saleable_id'] = $request->flash_sale_id;
            }
        }
    }


    private function handleSizes(Product $product, array $sizes)
    {
        foreach ($sizes as $sizeData) {
            $existing = $product->sizes()->where('size', $sizeData['size'])->first();

            if (!$existing) {
                $product->sizes()->create([
                    'size' => $sizeData['size'],
                    'price' => $sizeData['price'],
                    'stock' => $sizeData['stock'],
                ]);
            }
        }
    }


    public function createProduct(array $data): Product
    {
        return $this->productsRepository->create($data);
    }
    public function listAllProducts(array $queryParameters)
    {
        $criteria = (new ListAllProductsRequest)->constructQueryCriteria($queryParameters);
        $products = $this->productsRepository->findAllBy($criteria);

        return [
            'data' => new ProductCollection($products['data']),
            'count' => $products['count']
        ];
    }


    public function constructProductModel($request)
    {
        $healthIssues = array_filter($request['health_issues'] ?? []);

        return [
            'name_ar'        => $request['name_ar'],
            'name_en'        => $request['name_en'],
            'description_ar' => $request['description_ar'],
            'description_en' => $request['description_en'],
            'rate'           => $request['rate'],
            'image'          => $request['image'],
            'health_issues'  => $healthIssues,
            'category_id'    => $request['category_id'],
            'brand_id'       => $request['brand_id'] ?? null,
            'saleable_id'    => $request['saleable_id'] ?? null,
            'saleable_type'  => $request['saleable_type'] ?? null,
            'images'         => $request['images'] ?? [],
        ];
    }








    public function searchProducts(array $queryCriteria)
    {
        $result = $this->productsRepository->executeGetMany(Product::query(), $queryCriteria);


        return $result;
    }





    // latest products
    public function getLatestProducts(Request $request): array
    {
        $limit = request()->get('limit', 10);
        $offset = request()->get('offset', 0);
        $products = Product::with(['brand', 'category', 'sizes', 'saleable', 'productImages'])
            ->latest()
            ->take(10)
            ->offset($offset)
            ->limit($limit)
            ->get();

        return [
            'data' => $products,
            'count' => $products->count(),
        ];
    }
    private function calculateDiscountPercentage($product)
    {
        $priceBefore = $product->available_sizes->first()['original_price'] ?? 0;
        $priceAfter = $product->final_price;

        if ($priceBefore > 0 && $priceBefore > $priceAfter) {
            return round((($priceBefore - $priceAfter) / $priceBefore) * 100, 2);
        }

        return 0;
    }



    // top descount
    public function getTopDiscountProducts(Request $request): array
{
    $limit = $request->get('limit', 10);
    $offset = $request->get('offset', 0);
    $sortOrder = strtoupper($request->get('sort', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
    $sortBy = $request->get('sortBy', 'id');

    // 1. Get highest discount values
    $maxPercentValue = DB::table('discount_rules')
        ->where('discount_type', 'percent')
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->max('discount_value');

    $maxFixedValue = DB::table('discount_rules')
        ->where('discount_type', 'fixed')
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->max('discount_value');

    // 2. Get all rules with highest discount values
    $topPercentRules = DB::table('discount_rules')
        ->where('discount_type', 'percent')
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->where('discount_value', $maxPercentValue)
        ->get();

    $topFixedRules = DB::table('discount_rules')
        ->where('discount_type', 'fixed')
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->where('discount_value', $maxFixedValue)
        ->get();

    // 3. Fetch products for all rules
    $productsPercent = collect();
    foreach ($topPercentRules as $rule) {
        $productsPercent = $productsPercent->merge(
            $this->getProductsBytopDiscountRule($rule->id)
        );
    }
    $productsPercent = $productsPercent->unique('id');

    $productsFixed = collect();
    foreach ($topFixedRules as $rule) {
        $productsFixed = $productsFixed->merge(
            $this->getProductsBytopDiscountRule($rule->id)
        );
    }
    $productsFixed = $productsFixed->unique('id');

    $paginatedPercent = $productsPercent->slice($offset, $limit)->values();
    $paginatedFixed = $productsFixed->slice($offset, $limit)->values();

    // 4. Transform each product
    $transform = function ($product) use ($maxPercentValue, $maxFixedValue) {
        $discountValue = $product->discount_type === 'percent' ? $maxPercentValue : $maxFixedValue;

        // inject discount details inside available_sizes
        $sizesWithDiscount = collect($product->available_sizes)->map(function ($size) use ($product, $discountValue) {
            $priceBefore = (float) $size['original_price'];

            if ($product->discount_type === 'percent') {
                $priceAfter = $priceBefore - ($priceBefore * ($discountValue / 100));
            } else {
                $priceAfter = max(0, $priceBefore - $discountValue);
            }

            return array_merge($size, [
                'discount_type' => $product->discount_type,
                'discount_value' => $discountValue,
                'price_after_discount' => round($priceAfter, 2),
            ]);
        });

        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'main_image' => $product->image_url,
            'images' => $product->images,
            'brand' => $product->brand->name ?? null,
            'category' => $product->category->name ?? null,
            'price_before_discount' => $product->available_sizes->first()['original_price'] ?? 0,
            'price_after_discount' => $product->final_price,
            'discount_type' => $product->discount_type,
            'available_sizes' => $sizesWithDiscount,
            'sizes' => $product->sizes,
            'is_favorite' => $product->is_favorite,
            'rate' => $product->rate,
            'ratings_count' => $product->ratings_count,
            'average_rating' => $product->average_rating,
        ];
    };

    return [
        'data' => [
            'top_percent_discount' => [
                'discount_value' => $maxPercentValue,
                'products' => $paginatedPercent->map($transform),
                'total' => $productsPercent->count(),
            ],
            'top_fixed_discount' => [
                'discount_value' => $maxFixedValue,
                'products' => $paginatedFixed->map($transform),
                'total' => $productsFixed->count(),
            ],
        ],
        'meta' => [
            'limit' => (int) $limit,
            'offset' => (int) $offset,
            'sort' => $sortOrder,
            'sortBy' => $sortBy,
        ],
        'count' => $paginatedPercent->count() + $paginatedFixed->count(),
    ];
}


    private function getProductsBytopDiscountRule($ruleId)
    {
        $targets = DB::table('discount_rule_targets')
            ->where('discount_rule_id', $ruleId)
            ->get();

        $productQuery = Product::query();

        $productQuery->where(function ($query) use ($targets) {
            foreach ($targets as $target) {
                if ($target->target_type === 'product') {
                    $query->orWhere('id', $target->target_id);
                } elseif ($target->target_type === 'brand') {
                    $query->orWhere('brand_id', $target->target_id);
                } elseif ($target->target_type === 'category') {
                    $query->orWhere('category_id', $target->target_id);
                }
            }
        });

        return $productQuery
            ->with(['sizes', 'productImages', 'brand', 'category'])
            ->get();
    }


    // products on sale

    // public function listAllProductsOnSale(Request $request): array
    // {
    //     $limit = $request->get('limit', 10);
    //     $offset = $request->get('offset', 0);
    //     $sortOrder = strtoupper($request->get('sort', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
    //     $sortBy = $request->get('sortBy', 'id');
    //     // 1. Get top percent and fixed discount rules (active only)
    //     $topPercentRule = DB::table('discount_rules')
    //         ->where('discount_type', 'percent')
    //         ->where('starts_at', '<=', now())
    //         ->where('ends_at', '>=', now())
    //         ->orderByDesc('discount_value')
    //         ->first();

    //     $topFixedRule = DB::table('discount_rules')
    //         ->where('discount_type', 'fixed')
    //         ->where('starts_at', '<=', now())
    //         ->where('ends_at', '>=', now())
    //         ->orderByDesc('discount_value')
    //         ->first();

    //     // 2. Fetch products for each rule
    //     $productsPercent = $topPercentRule ? $this->getProductsByDiscountRule($topPercentRule->id) : collect();
    //     $productsFixed = $topFixedRule ? $this->getProductsByDiscountRule($topFixedRule->id) : collect();
    //     $paginatedPercent = $productsPercent->slice($offset, $limit)->values();
    //     $paginatedFixed = $productsFixed->slice($offset, $limit)->values();
    //     // 3. Transform products to return format
    //     $transform = fn($product) => [
    //         'id' => $product->id,
    //         'name' => $product->name,
    //         'description' => $product->description,
    //         'main_image' => $product->image_url,
    //         'images' => $product->images,
    //         'brand' => $product->brand->name ?? null,
    //         'category' => $product->category->name ?? null,
    //         'price_before_discount' => $product->available_sizes->first()['original_price'] ?? 0,
    //         'price_after_discount' => $product->final_price,
    //         'discount_type' => $product->discount_type,
    //         'is_favorite' => $product->is_favorite,
    //         'rate' => $product->rate,
    //         'ratings_count' => $product->ratings_count,
    //         'available_sizes' => $product->available_sizes,
    //         'sizes' => $product->sizes,
    //         'average_rating' => $product->average_rating,
    //     ];


    //     return [
    //         'data' => [
    //             'top_percent_discount' => [
    //                 'discount_value' => $topPercentRule->discount_value ?? null,
    //                 'products' => $paginatedPercent,
    //                 'total' => $productsPercent->count(),
    //             ],
    //             'top_fixed_discount' => [
    //                 'discount_value' => $topFixedRule->discount_value ?? null,
    //                 'products' => $paginatedFixed,
    //                 'total' => $productsFixed->count(),
    //             ],
    //         ],
    //         'meta' => [
    //             'limit' => $limit,
    //             'offset' => $offset,
    //             'sort' => $sortOrder,
    //             'sortBy' => $sortBy,
    //         ],
    //         'count' => $paginatedPercent->count() + $paginatedFixed->count(),
    //     ];
    // }
    // private function getProductsByDiscountRule($ruleId)
    // {
    //     $targets = DB::table('discount_rule_targets')
    //         ->where('discount_rule_id', $ruleId)
    //         ->get();

    //     $productIds = collect();

    //     foreach ($targets as $target) {
    //         if ($target->target_type === 'product') {
    //             $productIds->push($target->target_id);
    //         } elseif ($target->target_type === 'brand') {
    //             $productIds = $productIds->merge(
    //                 Product::where('brand_id', $target->target_id)->pluck('id')
    //             );
    //         } elseif ($target->target_type === 'category') {
    //             $productIds = $productIds->merge(
    //                 Product::where('category_id', $target->target_id)->pluck('id')
    //             );
    //         }
    //     }

    //     return Product::with(['sizes', 'productImages', 'brand', 'category'])
    //         ->whereIn('id', $productIds->unique())
    //         ->get();
    // }

    public function listAllProductsOnSale(Request $request): array
{
    $limit = $request->get('limit', 10);
    $offset = $request->get('offset', 0);
    $sortOrder = strtoupper($request->get('sort', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
    $sortBy = $request->get('sortBy', 'id');

    // 1. Get top percent and fixed discount rules (active only)
    $topPercentRule = DB::table('discount_rules')
        ->where('discount_type', 'percent')
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->orderByDesc('discount_value')
        ->first();

    $topFixedRule = DB::table('discount_rules')
        ->where('discount_type', 'fixed')
        ->where('starts_at', '<=', now())
        ->where('ends_at', '>=', now())
        ->orderByDesc('discount_value')
        ->first();

    // 2. Fetch products for each rule
    $productsPercent = $topPercentRule ? $this->getProductsByDiscountRule($topPercentRule->id) : collect();
    $productsFixed = $topFixedRule ? $this->getProductsByDiscountRule($topFixedRule->id) : collect();

    // Apply sorting if needed
    $productsPercent = $productsPercent->sortBy($sortBy, SORT_REGULAR, $sortOrder === 'DESC');
    $productsFixed = $productsFixed->sortBy($sortBy, SORT_REGULAR, $sortOrder === 'DESC');

    // Paginate
    $paginatedPercent = $productsPercent->slice($offset, $limit)->values();
    $paginatedFixed = $productsFixed->slice($offset, $limit)->values();

    // 3. Transform products
    $transform = fn($product) => [
        'id' => $product->id,
        'name' => $product->name,
        'description' => $product->description,
        'main_image' => $product->image_url,
        'images' => $product->images,
        'brand' => $product->brand->name ?? null,
        'category' => $product->category->name ?? null,
        'price_before_discount' => $product->available_sizes->first()['original_price'] ?? 0,
        'price_after_discount' => $product->final_price,
        'discount_type' => $product->discount_type,
        'is_favorite' => $product->is_favorite,
        'rate' => $product->rate,
        'ratings_count' => $product->ratings_count,
        'average_rating' => $product->average_rating,
        'available_sizes' => $product->available_sizes,
        'sizes' => $product->sizes,
    ];

    // 4. Format response
    return [
        'data' => [
            'top_percent_discount' => [
                'discount_value' => $topPercentRule->discount_value ?? null,
                'products' => $paginatedPercent->map($transform),
                'total' => $productsPercent->count(),
            ],
            'top_fixed_discount' => [
                'discount_value' => $topFixedRule->discount_value ?? null,
                'products' => $paginatedFixed->map($transform),
                'total' => $productsFixed->count(),
            ],
        ],
        'meta' => [
            'limit' => (int) $limit,
            'offset' => (int) $offset,
            'sort' => $sortOrder,
            'sortBy' => $sortBy,
        ],
        'count' => $paginatedPercent->count() + $paginatedFixed->count(),
    ];
}


private function getProductsByDiscountRule($ruleId)
{
    $targets = DB::table('discount_rule_targets')
        ->where('discount_rule_id', $ruleId)
        ->get();

    $productIds = collect();

    foreach ($targets as $target) {
        if ($target->target_type === 'product') {
            $productIds->push($target->target_id);
        } elseif ($target->target_type === 'brand') {
            $productIds = $productIds->merge(
                Product::where('brand_id', $target->target_id)->pluck('id')
            );
        } elseif ($target->target_type === 'category') {
            $productIds = $productIds->merge(
                Product::where('category_id', $target->target_id)->pluck('id')
            );
        }
    }

    return Product::with(['sizes', 'productImages', 'brand', 'category'])
        ->whereIn('id', $productIds->unique())
        ->get();
}

    // show product
    public function getProductById($id)
    {
        return $this->productsRepository->find($id);
    }

    // delete products
    public function deleteProduct($id)
    {
        return $this->productsRepository->delete($id);
    }
}
