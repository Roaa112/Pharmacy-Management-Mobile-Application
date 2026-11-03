<?php

namespace App\Modules\DataTransfer\Entities;

use App\Models\Brand;
use App\Models\Category;
use App\Models\HealthIssue;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductSize;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Modules\DataTransfer\Import\GroupPlan;
use App\Modules\DataTransfer\Import\ImportContext;
use App\Modules\DataTransfer\Support\Column;
use App\Modules\DataTransfer\Support\ImageFetcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Products with their sizes (Product → ProductSize: the sellable variants with SKU, barcode,
 * price, cost and stock settings). One file row per size; the product's own columns repeat on
 * each of its rows and the rows of one product (same product_code) are kept together.
 *
 * Matching: products by product_code (products.code, stable, never the name); sizes by
 * variant_sku, else variant_barcode, else the size label within the product. Category and brand
 * by id (a name is accepted only when it matches exactly one, with a warning; nothing is ever
 * created from a name).
 *
 * Stock is never written as a number: a new size's "stock" is its opening quantity, recorded as
 * an "initial" stock movement in the default warehouse (as the product form does). For existing
 * sizes the column is ignored (with a warning when it differs); stock changes go through the
 * Inventory import, which records adjustments.
 *
 * Sizes and images missing from the file are left as they are (an import never deletes).
 */
class ProductEntity extends DataEntity
{
    private const PRODUCT_COLUMNS = ['name_en', 'name_ar', 'description_en', 'description_ar', 'category_id', 'brand_id', 'health_issue_ids', 'image_url', 'extra_image_urls'];

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Products';
    }

    public function description(): string
    {
        return 'Products and their sizes (variants): names, category, brand, images, SKU, barcode, price, cost and stock levels.';
    }

    public function importPermission(): ?string
    {
        return 'products.import';
    }

    public function exportPermission(): ?string
    {
        return 'products.export';
    }

    public function matchDescription(): string
    {
        return 'Products by product_code; sizes by variant_sku, then variant_barcode, then the size label within the product.';
    }

    public function columns(): array
    {
        return [
            'product_code' => Column::make('Product code', 'Stable identifier of the product. All rows of one product share it and must be next to each other.', true, 'Letters, numbers, - and _ (max 40)', 'VITC-500'),
            'name_en' => Column::make('Name (English)', 'Required for a new product; empty keeps the current name.', true, 'Text, max 255', 'Vitamin C 500 mg'),
            'name_ar' => Column::make('Name (Arabic)', 'Required for a new product.', true, 'Text, max 255', 'فيتامين سي 500 مجم'),
            'description_en' => Column::make('Description (English)', '', false, 'Text', 'Immune support tablets.'),
            'description_ar' => Column::make('Description (Arabic)', '', false, 'Text', ''),
            'category_id' => Column::make('Category id', 'Id of an existing category or subcategory (see the category export). Required for a new product.', true, 'Existing category id', '3'),
            'category_name' => Column::make('Category name', 'Export: for reading. Import: used only when category_id is empty and the name matches exactly one category.', false, 'Existing category name', 'Vitamins'),
            'brand_id' => Column::make('Brand id', 'Id of an existing brand.', false, 'Existing brand id', '2'),
            'brand_name' => Column::make('Brand name', 'Like category_name, for brands.', false, 'Existing brand name', 'Pharma Co'),
            'health_issue_ids' => Column::make('Health issue ids', 'Replaces the product\'s health issues when filled. Empty keeps them.', false, 'Ids separated by |', '1|4'),
            'image_url' => Column::make('Main image', 'Required for a new product. An http(s) URL (downloaded, JPG/PNG/WebP up to 5 MB) or an existing path such as products/abc.png.', true, 'URL or path', 'https://example.com/vitc.jpg'),
            'extra_image_urls' => Column::make('Extra images', 'Added to the product (existing ones are kept).', false, 'URLs or paths separated by |', ''),
            'variant_sku' => Column::make('Size SKU', 'Unique across all products. Recommended: it is how a size is matched on update.', false, 'Max 64, unique', 'VITC-500-30'),
            'variant_barcode' => Column::make('Size barcode', 'Unique across all products.', false, 'Max 64, unique', '6221234567890'),
            'size' => Column::make('Size', 'The size / pack label. Unique within the product.', true, 'Text, max 50', '30 tablets'),
            'color' => Column::make('Color', '', false, 'Text, max 50', ''),
            'price' => Column::make('Price', 'Selling price before promotions.', true, 'Number > 0, 2 decimals', '120.00'),
            'cost_price' => Column::make('Cost price', '', false, 'Number ≥ 0', '80.00'),
            'stock' => Column::make('Opening stock', 'New sizes only: units put in the default warehouse as an "initial" stock movement. Ignored for existing sizes (use the inventory import).', false, 'Whole number ≥ 0', '25'),
            'min_stock' => Column::make('Minimum stock', '', false, 'Whole number ≥ 0', ''),
            'max_stock' => Column::make('Maximum stock', '', false, 'Whole number ≥ 0', ''),
            'reorder_point' => Column::make('Reorder point', 'Low-stock level of this size.', false, 'Whole number ≥ 0', '5'),
            'reorder_quantity' => Column::make('Reorder quantity', '', false, 'Whole number ≥ 0', '20'),
            'product_id' => Column::exportOnly('Product id', 'Database id (export only).'),
            'variant_id' => Column::exportOnly('Size id', 'Database id (export only).'),
            'stock_status' => Column::exportOnly('Stock status', 'in_stock / low / out_of_stock (export only).'),
            'reserved' => Column::exportOnly('Reserved units', 'Held for open orders (export only).', false),
            'sale_price' => Column::exportOnly('Price after promotions', 'Catalog price with the active promotion (export only; slower).', false),
            'deleted' => Column::exportOnly('Deleted', '1 when the product was removed from the shop (export only).', false),
            'created_at' => Column::exportOnly('Created at'),
            'updated_at' => Column::exportOnly('Updated at'),
        ];
    }

    public function filters(): array
    {
        return [
            'category_id' => ['Category', 'select', Category::orderBy('name_en')->pluck('name_en', 'id')->all()],
            'brand_id' => ['Brand', 'select', Brand::orderBy('name_en')->pluck('name_en', 'id')->all()],
            'supplier_id' => ['Supplier (purchased from)', 'select', \App\Models\Supplier::orderBy('name')->pluck('name', 'id')->all()],
            'status' => ['Status', 'select', ['active' => 'In the shop', 'deleted' => 'Deleted', 'all' => 'All']],
            'stock_status' => ['Stock', 'select', ['in_stock' => 'In stock', 'low' => 'Low stock', 'out_of_stock' => 'Out of stock']],
            'price_min' => ['Price from', 'number'],
            'price_max' => ['Price to', 'number'],
            'created_from' => ['Created from', 'date'],
            'created_to' => ['Created to', 'date'],
            'updated_from' => ['Updated from', 'date'],
            'updated_to' => ['Updated to', 'date'],
        ];
    }

    public function exportQuery(array $filters): Builder
    {
        $threshold = Setting::current()->lowStockThreshold();

        return Product::query()->withoutGlobalScope('listing_aggregates')
            ->with(['category:id,name_en', 'brand:id,name_en', 'healthIssues:id', 'productImages:id,product_id,image_path'])
            ->when(($filters['status'] ?? 'active') === 'deleted', fn ($q) => $q->onlyTrashed())
            ->when(($filters['status'] ?? null) === 'all', fn ($q) => $q->withTrashed())
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id))
            ->when($filters['supplier_id'] ?? null, fn ($q, $id) => $q->whereHas('sizes', fn ($s) => $s->whereIn('id',
                PurchaseOrderItem::whereHas('purchaseOrder', fn ($po) => $po->where('supplier_id', $id))->select('product_size_id'))))
            ->when(($filters['stock_status'] ?? null) === 'out_of_stock', fn ($q) => $q->whereDoesntHave('sizes', fn ($s) => $s->where('stock', '>', 0)))
            ->when(($filters['stock_status'] ?? null) === 'in_stock', fn ($q) => $q->whereHas('sizes', fn ($s) => $s->where('stock', '>', 0)))
            ->when(($filters['stock_status'] ?? null) === 'low', fn ($q) => $q->whereHas('sizes', fn ($s) => $s->whereRaw('stock <= coalesce(reorder_point, ?)', [$threshold])))
            ->when(isset($filters['price_min']) && $filters['price_min'] !== '', fn ($q) => $q->whereHas('sizes', fn ($s) => $s->where('price', '>=', $filters['price_min'])))
            ->when(isset($filters['price_max']) && $filters['price_max'] !== '', fn ($q) => $q->whereHas('sizes', fn ($s) => $s->where('price', '<=', $filters['price_max'])))
            ->when($filters['created_from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['created_to'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($filters['updated_from'] ?? null, fn ($q, $d) => $q->where('updated_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($filters['updated_to'] ?? null, fn ($q, $d) => $q->where('updated_at', '<=', Carbon::parse($d)->endOfDay()));
    }

    public function exportRows(Model $product): iterable
    {
        $threshold = Setting::current()->lowStockThreshold();
        $base = [
            'product_code' => $product->code,
            'name_en' => $product->getRawOriginal('name_en'),
            'name_ar' => $product->getRawOriginal('name_ar'),
            'description_en' => $product->getRawOriginal('description_en'),
            'description_ar' => $product->getRawOriginal('description_ar'),
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name_en,
            'brand_id' => $product->brand_id,
            'brand_name' => $product->brand?->name_en,
            'health_issue_ids' => $product->healthIssues->pluck('id')->implode('|'),
            'image_url' => $product->image,
            'extra_image_urls' => $product->productImages->pluck('image_path')->implode('|'),
            'product_id' => $product->id,
            'deleted' => $product->trashed() ? 1 : 0,
            'created_at' => $product->created_at,
            'updated_at' => $product->updated_at,
        ];

        if ($product->sizes->isEmpty()) {
            yield $base;

            return;
        }

        foreach ($product->sizes as $size) {
            yield $base + [
                'variant_sku' => $size->sku,
                'variant_barcode' => $size->barcode,
                'size' => $size->size,
                'color' => $size->color,
                'price' => $size->price,
                'cost_price' => $size->cost_price,
                'stock' => (int) $size->stock,
                'min_stock' => $size->min_stock,
                'max_stock' => $size->max_stock,
                'reorder_point' => $size->reorder_point,
                'reorder_quantity' => $size->reorder_quantity,
                'variant_id' => $size->id,
                'stock_status' => $size->stock <= 0 ? 'out_of_stock' : ($size->needsReorder($threshold) ? 'low' : 'in_stock'),
                // Computed only when the column is exported (ExportManager calls closures lazily).
                'reserved' => fn () => (int) $size->balances()->sum('reserved'),
                'sale_price' => fn () => $product->priceForSize($size),
            ];
        }
    }

    public function examples(): array
    {
        return [
            ['product_code' => 'VITC-500', 'name_en' => 'Vitamin C 500 mg', 'name_ar' => 'فيتامين سي 500 مجم', 'category_id' => '3', 'brand_id' => '2',
                'image_url' => 'https://example.com/vitc.jpg', 'variant_sku' => 'VITC-500-30', 'variant_barcode' => '6221234567890', 'size' => '30 tablets',
                'price' => '120.00', 'cost_price' => '80.00', 'stock' => '25', 'reorder_point' => '5'],
            ['product_code' => 'VITC-500', 'name_en' => 'Vitamin C 500 mg', 'name_ar' => 'فيتامين سي 500 مجم', 'category_id' => '3', 'brand_id' => '2',
                'image_url' => 'https://example.com/vitc.jpg', 'variant_sku' => 'VITC-500-60', 'size' => '60 tablets', 'price' => '210.00', 'cost_price' => '140.00', 'stock' => '10'],
        ];
    }

    public function notes(): array
    {
        return [
            'One row per size. Repeat the product columns on every row of the product and keep its rows together.',
            'Create only: product_code must be new. Update only: product_code must exist. Create or update: either.',
            'Sizes not in the file and images not in the file are kept; nothing is deleted by an import.',
            'Stock of existing sizes is never changed by this import: use the Inventory import (recorded as adjustments).',
            'Columns marked "export only" are ignored when importing, so an export can be imported again as it is.',
        ];
    }

    public function groupKey(array $row): ?string
    {
        return mb_strtolower((string) ($row['product_code'] ?? ''));
    }

    public function validate(GroupPlan $plan, ImportContext $context): void
    {
        $first = $plan->firstRow();
        $row = $plan->rows[$first];
        $code = self::value($row, 'product_code');

        if ($code === null) {
            $plan->error($first, 'product_code', 'product_code is required.');

            return;
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $code)) {
            $plan->error($first, 'product_code', 'Use letters, numbers, - and _ (max 40).', $code);

            return;
        }

        $product = Product::withTrashed()->with('sizes')->where('code', $code)->first();
        if ($product?->trashed()) {
            $plan->error($first, 'product_code', "Product {$code} was deleted; restore it from the dashboard before updating it.", $code);

            return;
        }

        if ($product && !$context->allowsUpdate()) {
            $plan->error($first, 'product_code', "Product code \"{$code}\" already exists (create-only import).", $code);

            return;
        }
        if (!$product && !$context->allowsCreate()) {
            $plan->error($first, 'product_code', "Product code \"{$code}\" does not exist (update-only import).", $code);

            return;
        }

        $plan->action = $product ? GroupPlan::UPDATE : GroupPlan::CREATE;
        $plan->identifier = $code;

        $attributes = $this->validateProduct($plan, $first, $row, $product);
        $this->warnInconsistentRows($plan, $row);
        $variants = $this->validateVariants($plan, $context, $product);

        $plan->payload = ['product_id' => $product?->id, 'code' => $code] + $attributes + ['variants' => $variants];
    }

    private function validateProduct(GroupPlan $plan, int $first, array $row, ?Product $product): array
    {
        $creating = $product === null;
        $data = $this->check($plan, $first, [
            'name_en' => self::value($row, 'name_en'),
            'name_ar' => self::value($row, 'name_ar'),
            'description_en' => self::value($row, 'description_en'),
            'description_ar' => self::value($row, 'description_ar'),
        ], [
            'name_en' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'name_ar' => [$creating ? 'required' : 'nullable', 'string', 'max:255'],
            'description_en' => ['nullable', 'string', 'max:20000'],
            'description_ar' => ['nullable', 'string', 'max:20000'],
        ]);

        $categoryId = $this->resolve($plan, $first, $row, 'category', Category::class, $creating);
        $brandId = $this->resolve($plan, $first, $row, 'brand', Brand::class, false);

        $healthIds = null;
        if (($raw = self::value($row, 'health_issue_ids')) !== null) {
            $healthIds = array_map('intval', self::list($raw));
            $missing = array_diff($healthIds, HealthIssue::whereIn('id', $healthIds)->pluck('id')->all());
            if ($missing || array_filter(self::list($raw), fn ($v) => !ctype_digit($v))) {
                $plan->error($first, 'health_issue_ids', 'Unknown health issue ids: '.implode(', ', $missing ?: self::list($raw)).'.', $raw);
            }
        }

        $fetcher = app(ImageFetcher::class);
        $image = self::value($row, 'image_url');
        if ($image === null && $creating) {
            $plan->error($first, 'image_url', 'A main image (URL or existing path) is required for a new product.');
        } elseif ($image !== null && $image !== $product?->image && ($problem = $fetcher->check($image))) {
            $plan->error($first, 'image_url', $problem, $image);
        }

        $existingExtras = $product ? $product->productImages()->pluck('image_path')->all() : [];
        $extras = array_values(array_diff(self::list(self::value($row, 'extra_image_urls')), $existingExtras));
        foreach ($extras as $extra) {
            if ($problem = $fetcher->check($extra)) {
                $plan->error($first, 'extra_image_urls', $problem, $extra);
            }
        }

        return [
            'attributes' => array_filter([
                'name_en' => $data['name_en'] ?? null,
                'name_ar' => $data['name_ar'] ?? null,
                'description_en' => $data['description_en'] ?? null,
                'description_ar' => $data['description_ar'] ?? null,
                'category_id' => $categoryId,
                'brand_id' => $brandId,
            ], fn ($v) => $v !== null),
            'health_issue_ids' => $healthIds,
            'image' => $image !== null && $image !== $product?->image ? $image : null,
            'extra_images' => $extras,
        ];
    }

    /** category_id / brand_id by id; a name only if it matches exactly one record. */
    private function resolve(GroupPlan $plan, int $rowNumber, array $row, string $kind, string $model, bool $required): ?int
    {
        $id = self::value($row, "{$kind}_id");
        $name = self::value($row, "{$kind}_name");

        if ($id !== null) {
            if (!ctype_digit($id) || !$model::whereKey((int) $id)->exists()) {
                $plan->error($rowNumber, "{$kind}_id", ucfirst($kind)." {$id} does not exist.", $id);

                return null;
            }

            return (int) $id;
        }

        if ($name !== null) {
            $matches = $model::where('name_en', $name)->orWhere('name_ar', $name)->limit(2)->pluck('id');
            if ($matches->count() === 1) {
                $plan->warning($rowNumber, "{$kind}_name", ucfirst($kind)." found by name (id {$matches[0]}); use {$kind}_id to be explicit.", $name);

                return (int) $matches[0];
            }
            $plan->error($rowNumber, "{$kind}_name", $matches->isEmpty()
                ? ucfirst($kind)." \"{$name}\" does not exist."
                : ucfirst($kind)." name \"{$name}\" matches several records: use {$kind}_id.", $name);

            return null;
        }

        if ($required) {
            $plan->error($rowNumber, "{$kind}_id", "{$kind}_id is required for a new product.");
        }

        return null;
    }

    private function warnInconsistentRows(GroupPlan $plan, array $first): void
    {
        foreach (array_slice($plan->rows, 1, null, true) as $number => $row) {
            foreach (self::PRODUCT_COLUMNS as $column) {
                $value = self::value($row, $column);
                if ($value !== null && $value !== self::value($first, $column)) {
                    $plan->warning($number, $column, 'Differs from the product\'s first row; the first row\'s value is used.', $value);
                }
            }
        }
    }

    private function validateVariants(GroupPlan $plan, ImportContext $context, ?Product $product): array
    {
        $variants = [];
        $labels = [];

        foreach ($plan->rows as $number => $row) {
            $data = $this->check($plan, $number, [
                'variant_sku' => self::value($row, 'variant_sku'),
                'variant_barcode' => self::value($row, 'variant_barcode'),
                'size' => self::value($row, 'size'),
                'color' => self::value($row, 'color'),
                'price' => self::value($row, 'price'),
                'cost_price' => self::value($row, 'cost_price'),
                'stock' => self::value($row, 'stock'),
                'min_stock' => self::value($row, 'min_stock'),
                'max_stock' => self::value($row, 'max_stock'),
                'reorder_point' => self::value($row, 'reorder_point'),
                'reorder_quantity' => self::value($row, 'reorder_quantity'),
            ], [
                'variant_sku' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-.\/]+$/'],
                'variant_barcode' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
                'size' => ['required', 'string', 'max:50'],
                'color' => ['nullable', 'string', 'max:50'],
                'price' => ['required', 'numeric', 'gt:0', 'max:99999999.99', 'decimal:0,2'],
                'cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
                'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'min_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'max_stock' => ['nullable', 'integer', 'min:0', 'max:1000000', 'gte:min_stock'],
                'reorder_point' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'reorder_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            ]);

            $sku = $data['variant_sku'] ?? null;
            $barcode = $data['variant_barcode'] ?? null;
            $label = $data['size'] ?? null;

            if ($label !== null) {
                if (isset($labels[mb_strtolower($label)])) {
                    $plan->error($number, 'size', "Size \"{$label}\" appears twice for this product (rows {$labels[mb_strtolower($label)]} and {$number}).", $label);
                }
                $labels[mb_strtolower($label)] = $number;
            }
            if ($other = $context->claim('sku', $sku, $number)) {
                $plan->error($number, 'variant_sku', "Duplicate SKU in the file (also on row {$other}).", $sku);
            }
            if ($other = $context->claim('barcode', $barcode, $number)) {
                $plan->error($number, 'variant_barcode', "Duplicate barcode in the file (also on row {$other}).", $barcode);
            }

            $existing = $this->matchVariant($plan, $number, $product, $sku, $barcode, $label);

            if ($existing && $data['stock'] !== null && (int) $data['stock'] !== (int) $existing->stock) {
                $plan->warning($number, 'stock', "Stock of an existing size is not changed by a product import (current {$existing->stock}); use the inventory import.", $data['stock']);
            }

            $variants[] = [
                'row' => $number,
                'size_id' => $existing?->id,
                'opening_stock' => $existing ? 0 : (int) ($data['stock'] ?? 0),
                'attributes' => array_filter([
                    'size' => $label,
                    'sku' => $sku,
                    'barcode' => $barcode,
                    'color' => $data['color'] ?? null,
                    'price' => $data['price'] ?? null,
                    'cost_price' => $data['cost_price'] ?? null,
                    'min_stock' => $data['min_stock'] ?? null,
                    'max_stock' => $data['max_stock'] ?? null,
                    'reorder_point' => $data['reorder_point'] ?? null,
                    'reorder_quantity' => $data['reorder_quantity'] ?? null,
                ], fn ($v) => $v !== null),
            ];
        }

        return $variants;
    }

    private function matchVariant(GroupPlan $plan, int $number, ?Product $product, ?string $sku, ?string $barcode, ?string $label): ?ProductSize
    {
        foreach (['sku' => $sku, 'barcode' => $barcode] as $column => $value) {
            if ($value === null) {
                continue;
            }
            $size = ProductSize::where($column, $value)->first();
            if (!$size) {
                continue;
            }
            if ($size->product_id !== $product?->id) {
                $owner = Product::withTrashed()->whereKey($size->product_id)->value('code');
                $plan->error($number, "variant_{$column}", strtoupper($column)." \"{$value}\" already belongs to product {$owner}.", $value);

                return null;
            }

            return $size;
        }

        if (!$product || $label === null) {
            return null;
        }

        $size = $product->sizes->first(fn (ProductSize $s) => mb_strtolower($s->size) === mb_strtolower($label));
        if ($size && $sku !== null && $size->sku !== null && $size->sku !== $sku) {
            $plan->error($number, 'size', "Size \"{$label}\" already exists with SKU {$size->sku}; use that SKU or another size label.", $label);

            return null;
        }

        return $size;
    }

    public function persist(GroupPlan $plan, ImportContext $context): void
    {
        $p = $plan->payload;
        $fetcher = app(ImageFetcher::class);
        $downloaded = [];

        try {
            $image = $p['image'] ? $downloaded[] = $fetcher->fetch($p['image'], 'products') : null;
            $extras = array_map(fn ($url) => $downloaded[] = $fetcher->fetch($url, 'products'), $p['extra_images']);

            $product = $p['product_id'] ? Product::lockForUpdate()->findOrFail($p['product_id']) : new Product(['code' => $p['code']]);
            $product->forceFill($p['attributes'] + ($image ? ['image' => $image] : []));
            $product->code = $p['code'];
            $product->save();

            if ($p['health_issue_ids'] !== null) {
                $product->healthIssues()->sync($p['health_issue_ids']);
            }
            foreach ($extras as $path) {
                ProductImage::create(['product_id' => $product->id, 'image_path' => $path]);
            }

            foreach ($p['variants'] as $variant) {
                if ($variant['size_id']) {
                    ProductSize::whereKey($variant['size_id'])->lockForUpdate()->firstOrFail()->update($variant['attributes']);
                    continue;
                }

                $size = $product->sizes()->create($variant['attributes'] + ['stock' => 0]);
                if ($variant['opening_stock'] > 0) {
                    // Opening quantity as a recorded movement, never a number written into the column.
                    ProductSize::setStock($size->id, $variant['opening_stock'], StockMovement::TYPE_INITIAL, [
                        'admin_id' => $context->adminId, 'reason' => "Product import #{$context->runId}",
                    ]);
                }
            }
        } catch (\Throwable $e) {
            foreach ($downloaded as $path) {
                $fetcher->discard($path);
            }
            throw $e;
        }
    }
}
