<?php

namespace Tests\Feature\DataTransfer;

use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\ExportRun;
use App\Models\ImportRun;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\DataTransfer\Support\ImageFetcher;
use App\Modules\DataTransfer\Support\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;
    private Category $category;
    private Brand $brand;

    private const HEADER = ['product_code', 'name_en', 'name_ar', 'category_id', 'brand_id', 'image_url', 'variant_sku', 'variant_barcode', 'size', 'price', 'cost_price', 'stock'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => 'secret-password']);
        $this->category = Category::create(['name_ar' => 'فيتامينات', 'name_en' => 'Vitamins', 'image' => 'category/v.png']);
        $this->brand = Brand::create(['name_ar' => 'ماركة', 'name_en' => 'Pharma Co', 'image' => 'brands/b.png']);

        // Image downloads: no real network, and no DNS in tests.
        app()->instance(ImageFetcher::class, new class extends ImageFetcher {
            protected function publicAddress(string $host): string
            {
                return '93.184.216.34';
            }
        });
        Http::fake(['https://cdn.example.com/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII='), 200),
            'https://bad.example.com/*' => Http::response('<html>not an image</html>', 200)]);
    }

    protected function tearDown(): void
    {
        foreach (glob(public_path('products/*-*-*-*-*.png')) ?: [] as $file) {
            @unlink($file); // images downloaded by these tests
        }
        parent::tearDown();
    }

    private function csv(array $rows, array $header = self::HEADER, string $name = 'products.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        Spreadsheet::write($path, 'csv', ['Data' => [$header, ...array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $header), $rows)]]);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function xlsx(array $rows, array $header = self::HEADER): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        Spreadsheet::write($path, 'xlsx', ['Data' => [$header, ...array_map(fn ($r) => array_map(fn ($h) => $r[$h] ?? '', $header), $rows)], 'Notes' => [['ignored']]]);

        return new UploadedFile($path, 'products.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function row(array $overrides = []): array
    {
        return $overrides + ['product_code' => 'VITC', 'name_en' => 'Vitamin C', 'name_ar' => 'فيتامين سي', 'category_id' => $this->category->id,
            'brand_id' => $this->brand->id, 'image_url' => 'https://cdn.example.com/vitc.png', 'variant_sku' => 'VITC-30', 'size' => '30 tablets', 'price' => '120.00', 'cost_price' => '80', 'stock' => '25'];
    }

    private function upload(UploadedFile $file, string $mode = 'upsert', string $entity = 'products', ?Admin $as = null): ImportRun
    {
        $this->actingAs($as ?? $this->admin, 'admin')->post(route('dashboard.data.imports.store'), ['entity' => $entity, 'mode' => $mode, 'file' => $file])->assertRedirect();

        return ImportRun::latest('id')->firstOrFail();
    }

    private function confirm(ImportRun $run): ImportRun
    {
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.imports.confirm', $run))->assertRedirect();

        return $run->fresh();
    }

    private function errors(ImportRun $run): array
    {
        return collect($run->summary['processed']['sample_errors'] ?? $run->summary['sample_errors'] ?? [])->map(fn ($e) => $e['row'].':'.$e['column'].':'.$e['message'])->all();
    }

    private function exportRows(array $data): array
    {
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.exports.store'), $data)->assertRedirect();
        $run = ExportRun::latest('id')->firstOrFail();
        $this->assertSame('completed', $run->status, (string) $run->failure_message);

        return iterator_to_array(Spreadsheet::rows(Storage::disk('local')->path($run->file_path), $run->format), false);
    }

    // ---------------------------------------------------------------- templates

    public function test_templates_follow_the_schema_and_need_the_import_permission(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.template', ['products', 'format' => 'xlsx']))->assertOk();
        $headers = Spreadsheet::headers($response->getFile()->getPathname(), 'xlsx');
        $this->assertSame(['product_code', 'name_en', 'name_ar'], array_slice($headers, 0, 3));
        $this->assertContains('variant_sku', $headers);
        $this->assertNotContains('product_id', $headers, 'export-only columns are not in the template');

        $csv = $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.template', ['products', 'format' => 'csv']))->assertOk();
        $this->assertCount(2, iterator_to_array(Spreadsheet::rows($csv->getFile()->getPathname(), 'csv'), false), 'CSV template carries the example rows');

        foreach (['categories', 'brands', 'inventory', 'suppliers', 'cities', 'areas', 'loyalty_rewards'] as $entity) {
            $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.template', [$entity, 'format' => 'xlsx']))->assertOk();
        }
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.template', ['customers']))->assertForbidden(); // export only
    }

    // ---------------------------------------------------------------- product import

    public function test_product_import_previews_then_creates_products_sizes_images_and_opening_stock(): void
    {
        $run = $this->upload($this->csv([
            $this->row(),
            $this->row(['variant_sku' => 'VITC-60', 'size' => '60 tablets', 'price' => '200', 'stock' => '0']),
            $this->row(['product_code' => 'ZINC', 'name_en' => 'Zinc', 'variant_sku' => 'ZINC-1', 'size' => 'Box', 'price' => '50', 'image_url' => 'https://cdn.example.com/zinc.png']),
        ]));

        // Preview: nothing written yet.
        $this->assertTrue($run->awaitingConfirmation());
        $this->assertSame([3, 3, 0, 2, 0], [$run->total_rows, $run->valid_rows, $run->invalid_rows, $run->to_create, $run->to_update]);
        $this->assertSame(0, Product::where('code', 'VITC')->count());
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.imports.show', $run))->assertOk()->assertSee('Confirm import');

        $run = $this->confirm($run);

        $this->assertSame(['completed', 2, 0], [$run->status, $run->created_count, $run->failed_rows]);
        $product = Product::where('code', 'VITC')->firstOrFail();
        $this->assertSame([$this->category->id, $this->brand->id], [$product->category_id, $product->brand_id]);
        $this->assertFileExists(public_path($product->image));
        $this->assertSame(['30 tablets' => 25, '60 tablets' => 0], $product->sizes()->pluck('stock', 'size')->map(fn ($v) => (int) $v)->all());
        $movement = StockMovement::where('product_size_id', ProductSize::where('sku', 'VITC-30')->value('id'))->sole();
        $this->assertSame(['initial', 25], [$movement->type, (int) $movement->quantity_change]);
        $this->assertTrue(AdminAuditLog::where('action', 'data.import')->where('subject_id', $run->id)->exists());
    }

    public function test_xlsx_import_and_update_mode_never_change_stock(): void
    {
        $this->confirm($this->upload($this->xlsx([$this->row()])));
        $size = ProductSize::where('sku', 'VITC-30')->sole();

        $run = $this->confirm($this->upload($this->xlsx([
            $this->row(['price' => '99.50', 'stock' => '1000', 'name_en' => 'Vitamin C 1000']),
            $this->row(['variant_sku' => 'VITC-90', 'size' => '90 tablets', 'price' => '300', 'stock' => '4']),
        ]), 'update'));

        $this->assertSame(['completed', 1, 0], [$run->status, $run->updated_count, $run->created_count]);
        $this->assertEquals(99.5, $size->fresh()->price);
        $this->assertSame(25, (int) $size->fresh()->stock, 'stock of an existing size is never written by a product import');
        $this->assertSame(4, (int) ProductSize::where('sku', 'VITC-90')->value('stock'), 'a new size gets its opening stock');
        $this->assertSame('Vitamin C 1000', Product::where('code', 'VITC')->value('name_en'));
        // Stock 1000 ignored for the existing size; row 3's name differs from the product's first row.
        $this->assertSame(2, $run->warning_count);
    }

    public function test_modes_reject_what_they_do_not_allow(): void
    {
        $this->confirm($this->upload($this->csv([$this->row()])));

        $create = $this->upload($this->csv([$this->row()]), 'create');
        $this->assertSame([0, 1], [$create->valid_rows, $create->invalid_rows]);
        $this->assertStringContainsString('already exists', $this->errors($create)[0]);

        $update = $this->upload($this->csv([$this->row(['product_code' => 'NEW', 'variant_sku' => 'NEW-1'])]), 'update');
        $this->assertStringContainsString('does not exist', $this->errors($update)[0]);
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.imports.confirm', $update))->assertSessionHas('error');
    }

    public function test_row_validation_reports_every_problem_with_its_row(): void
    {
        $this->confirm($this->upload($this->csv([$this->row(['product_code' => 'OTHER', 'variant_sku' => 'TAKEN', 'variant_barcode' => '999'])])));

        $run = $this->upload($this->csv([
            $this->row(['product_code' => 'A', 'variant_sku' => 'A-1', 'category_id' => '9999']),                  // row 2: unknown category
            $this->row(['product_code' => 'B', 'variant_sku' => 'B-1', 'brand_id' => '9999']),                     // row 3: unknown brand
            $this->row(['product_code' => 'C', 'variant_sku' => 'C-1', 'price' => '0']),                           // row 4: price
            $this->row(['product_code' => 'D', 'variant_sku' => 'D-1', 'stock' => '-3']),                          // row 5: stock
            $this->row(['product_code' => 'E', 'variant_sku' => 'E-1', 'name_en' => '']),                          // row 6: required
            $this->row(['product_code' => 'F', 'variant_sku' => 'TAKEN']),                                         // row 7: SKU of another product
            $this->row(['product_code' => 'G', 'variant_sku' => 'G-1', 'variant_barcode' => '999']),              // row 8: barcode taken
            $this->row(['product_code' => 'H', 'variant_sku' => 'DUP']),                                           // row 9
            $this->row(['product_code' => 'I', 'variant_sku' => 'DUP']),                                           // row 10: duplicate in file
            $this->row(['product_code' => 'J', 'variant_sku' => 'J-1', 'size' => '']),                             // row 11: invalid variant
            $this->row(['product_code' => 'K', 'variant_sku' => 'K-1', 'image_url' => 'products/missing.png']),   // row 12: image
            $this->row(['product_code' => 'H', 'variant_sku' => 'H-2', 'size' => 'Big']),                          // row 13: rows not together
        ]));

        $errors = implode("\n", $this->errors($run));
        foreach (['2:category_id:Category 9999 does not exist', '3:brand_id:Brand 9999 does not exist', '4:price:', '5:stock:', '6:name_en:',
            '7:variant_sku:SKU "TAKEN" already belongs to product OTHER', '8:variant_barcode:', '10:variant_sku:Duplicate SKU in the file (also on row 9)',
            '11:size:', '12:image_url:', '13:product_code:'] as $expected) {
            $this->assertStringContainsString($expected, $errors);
        }
        $this->assertSame([12, 1, 11], [$run->total_rows, $run->valid_rows, $run->invalid_rows]);
        $this->assertGreaterThanOrEqual(1, $run->summary['duplicates']);
        $this->assertGreaterThanOrEqual(2, $run->summary['relationship_errors']);

        // The error report has every row.
        $report = $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.imports.file', [$run, 'errors']))->assertOk();
        $lines = array_filter(explode("\n", trim($report->streamedContent())));
        $this->assertGreaterThanOrEqual(12, count($lines));

        // Confirming imports only the valid record.
        $run = $this->confirm($run);
        $this->assertSame(['completed_with_errors', 1], [$run->status, $run->created_count]);
        $this->assertSame(['H', 'OTHER'], Product::orderBy('code')->pluck('code')->all());
    }

    public function test_a_record_that_fails_while_saving_leaves_nothing_behind(): void
    {
        $run = $this->upload($this->csv([
            $this->row(['product_code' => 'BROKEN', 'variant_sku' => 'BR-1', 'image_url' => 'https://bad.example.com/x.png']),
            $this->row(['product_code' => 'GOOD', 'variant_sku' => 'GO-1']),
        ]));
        $this->assertSame(2, $run->valid_rows, 'the URL looks fine until it is downloaded');

        $run = $this->confirm($run);

        $this->assertSame(['completed_with_errors', 1, 1], [$run->status, $run->created_count, $run->failed_rows]);
        $this->assertFalse(Product::where('code', 'BROKEN')->exists());
        $this->assertFalse(ProductSize::where('sku', 'BR-1')->exists());
        $this->assertTrue(Product::where('code', 'GOOD')->exists());
        $this->assertStringContainsString('JPG, PNG or WebP', implode(' ', $this->errors($run)));
    }

    public function test_large_files_are_processed_in_the_background_record_by_record(): void
    {
        config(['pharmacy.data_transfer.sync_max_bytes' => 0]); // always the queue (sync driver in tests)
        $rows = [];
        for ($i = 1; $i <= 600; $i++) {
            $rows[] = $this->row(['product_code' => "P{$i}", 'variant_sku' => "P{$i}-A", 'image_url' => 'category/v.png', 'stock' => '']);
            $rows[] = $this->row(['product_code' => "P{$i}", 'variant_sku' => "P{$i}-B", 'size' => 'Big', 'image_url' => 'category/v.png', 'stock' => '']);
        }
        touch(public_path('category/v.png'));

        $run = $this->confirm($this->upload($this->csv($rows)));

        $this->assertSame(['completed', 1200, 600], [$run->status, $run->total_rows, $run->created_count]);
        $this->assertSame(1200, ProductSize::count());
        @unlink(public_path('category/v.png'));
    }

    // ---------------------------------------------------------------- product export

    public function test_product_export_has_the_columns_relationships_and_filters(): void
    {
        $size = $this->makeSize(price: 40, stock: 3);
        $size->product->update(['category_id' => $this->category->id, 'brand_id' => $this->brand->id]);
        $size->update(['sku' => 'S-1', 'barcode' => '111', 'cost_price' => 20]);
        $size->product->sizes()->create(['size' => 'L', 'sku' => 'S-2', 'price' => 70, 'stock' => 0]);
        $other = $this->makeSize(price: 10);

        $rows = $this->exportRows(['entity' => 'products', 'format' => 'csv', 'filters' => ['category_id' => $this->category->id]]);

        $this->assertCount(2, $rows, 'one row per size, only the filtered category');
        // 3 units is at or below the store's low-stock threshold (5).
        $this->assertSame([$size->product->code, 'Vitamins', 'Pharma Co', 'S-1', '111', 40.0, 20.0, '3', 'low'],
            [$rows[0]['product_code'], $rows[0]['category_name'], $rows[0]['brand_name'], $rows[0]['variant_sku'], $rows[0]['variant_barcode'], (float) $rows[0]['price'], (float) $rows[0]['cost_price'], $rows[0]['stock'], $rows[0]['stock_status']]);
        $this->assertSame('out_of_stock', $rows[1]['stock_status']);

        $xlsx = $this->exportRows(['entity' => 'products', 'format' => 'xlsx', 'columns' => ['product_code', 'variant_sku', 'price'], 'filters' => ['price_min' => 50]]);
        $this->assertSame(['product_code', 'variant_sku', 'price'], array_keys($xlsx[0]));
        $this->assertCount(2, $xlsx, 'the product has a size priced ≥ 50: all its sizes are exported');
        $this->assertNotContains($other->product->code, array_column($xlsx, 'product_code'));

        $log = AdminAuditLog::where('action', 'data.export')->latest('id')->first();
        $this->assertSame(['products', 'xlsx', 2], [$log->changes['entity'], $log->changes['format'], $log->changes['records']]);
    }

    public function test_an_exported_file_imports_back_without_changing_anything(): void
    {
        $this->confirm($this->upload($this->csv([$this->row(), $this->row(['variant_sku' => 'VITC-60', 'size' => '60', 'price' => '200', 'stock' => '7'])])));
        $before = ProductSize::orderBy('id')->get(['sku', 'size', 'price', 'cost_price', 'stock'])->toArray();

        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.exports.store'), ['entity' => 'products', 'format' => 'xlsx'])->assertRedirect();
        $export = ExportRun::latest('id')->firstOrFail();
        $file = new UploadedFile(Storage::disk('local')->path($export->file_path), 'export.xlsx', null, null, true);

        $run = $this->confirm($this->upload($file, 'update'));

        $this->assertSame(['completed', 1, 0, 0], [$run->status, $run->updated_count, $run->created_count, $run->warning_count]);
        $this->assertSame($before, ProductSize::orderBy('id')->get(['sku', 'size', 'price', 'cost_price', 'stock'])->toArray());
        $this->assertSame(1, Product::count());
    }

    public function test_customer_export_never_contains_secrets_and_needs_its_permission(): void
    {
        User::factory()->create(['email' => 'c@example.com', 'password' => 'super-secret']);

        $rows = $this->exportRows(['entity' => 'customers', 'format' => 'csv', 'columns' => ['id', 'email', 'password', 'otp_code']]);
        $this->assertSame(['id', 'email'], array_keys($rows[0]), 'unknown or secret columns are dropped');
        $all = $this->exportRows(['entity' => 'customers', 'format' => 'csv']);
        foreach (['password', 'otp_code', 'remember_token', 'social_id'] as $secret) {
            $this->assertArrayNotHasKey($secret, $all[0]);
        }
        $this->assertTrue(AdminAuditLog::where('action', 'data.export')->get()->every(fn ($l) => $l->changes['sensitive'] === true));

        $role = new Role(['name' => 'Warehouse']);
        $role->slug = 'warehouse_only';
        $role->save();
        $role->permissions()->sync(Permission::whereIn('slug', ['inventory.view', 'inventory.export', 'inventory.import'])->pluck('id'));
        $warehouse = Admin::create(['name' => 'W', 'email' => 'w@example.com', 'password' => 'secret-password']);
        $warehouse->forceFill(['role' => 'warehouse_only'])->save();

        $this->actingAs($warehouse, 'admin')->post(route('dashboard.data.exports.store'), ['entity' => 'customers', 'format' => 'csv'])->assertForbidden();
        $this->actingAs($warehouse, 'admin')->post(route('dashboard.data.exports.store'), ['entity' => 'payments', 'format' => 'csv'])->assertForbidden();
        $this->actingAs($warehouse, 'admin')->post(route('dashboard.data.exports.store'), ['entity' => 'inventory', 'format' => 'csv'])->assertRedirect();
        $this->actingAs($warehouse, 'admin')->post(route('dashboard.data.imports.store'), ['entity' => 'products', 'mode' => 'upsert', 'file' => $this->csv([$this->row()])])->assertForbidden();
        $this->actingAs($warehouse, 'admin')->get(route('dashboard.data.index'))->assertOk()->assertDontSee('Customers</option>', false);

        // History only shows what the admin may move.
        $this->actingAs($warehouse, 'admin')->get(route('dashboard.data.exports.index'))->assertOk()->assertDontSee('>Customers<', false);
        $customersExport = ExportRun::where('entity', 'customers')->first();
        $this->actingAs($warehouse, 'admin')->get(route('dashboard.data.exports.download', $customersExport))->assertForbidden();
    }

    // ---------------------------------------------------------------- other entities

    public function test_inventory_import_records_adjustments_and_refuses_negative_stock(): void
    {
        $size = $this->makeSize(stock: 10);
        $size->update(['sku' => 'INV-1']);

        $run = $this->confirm($this->upload($this->csv([
            ['sku' => 'INV-1', 'set_on_hand' => '14', 'reason' => 'Count'],
        ], ['sku', 'warehouse_code', 'set_on_hand', 'adjust_by', 'reason']), 'update', 'inventory'));
        $this->assertSame(['completed', 1], [$run->status, $run->updated_count]);
        $this->assertSame(14, (int) $size->fresh()->stock);
        $movement = StockMovement::where('product_size_id', $size->id)->where('type', 'adjustment')->sole();
        $this->assertSame([4, 'Count', $this->admin->id], [(int) $movement->quantity_change, $movement->reason, $movement->admin_id]);

        $bad = $this->upload($this->csv([['sku' => 'INV-1', 'adjust_by' => '-20'], ['sku' => 'NOPE', 'adjust_by' => '1']], ['sku', 'adjust_by']), 'update', 'inventory');
        $this->assertSame([0, 2], [$bad->valid_rows, $bad->invalid_rows]);
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.imports.store'), ['entity' => 'inventory', 'mode' => 'upsert', 'file' => $this->csv([['sku' => 'INV-1']], ['sku'])])
            ->assertSessionHas('error');
    }

    public function test_categories_brands_suppliers_locations_and_rewards_round_trip(): void
    {
        touch(public_path('category/v.png'));
        $this->confirm($this->upload($this->csv([['name_en' => 'Skin care', 'name_ar' => 'العناية', 'image_url' => 'category/v.png', 'parent_id' => $this->category->id]],
            ['id', 'name_en', 'name_ar', 'parent_id', 'image_url']), 'create', 'categories'));
        $this->assertSame($this->category->id, Category::where('name_en', 'Skin care')->value('parent_id'));
        $duplicate = $this->upload($this->csv([['name_en' => 'Skin care', 'name_ar' => 'x', 'image_url' => 'category/v.png', 'parent_id' => $this->category->id]],
            ['id', 'name_en', 'name_ar', 'parent_id', 'image_url']), 'create', 'categories');
        $this->assertSame(1, $duplicate->invalid_rows, 'no duplicate categories from a name');

        $this->confirm($this->upload($this->csv([['code' => 'SUP-1', 'name' => 'Cairo Pharma', 'currency_code' => 'egp', 'payment_terms_days' => '30']],
            ['code', 'name', 'currency_code', 'payment_terms_days']), 'upsert', 'suppliers'));
        $this->assertSame('EGP', \App\Models\Supplier::where('code', 'SUP-1')->value('currency_code'));

        $this->confirm($this->upload($this->csv([['name' => 'Giza']], ['id', 'name']), 'create', 'cities'));
        $city = \App\Models\City::where('name', 'Giza')->sole();
        $this->confirm($this->upload($this->csv([['city_id' => $city->id, 'name' => 'Dokki']], ['id', 'city_id', 'name']), 'create', 'areas'));
        $this->assertTrue(\App\Models\Area::where('city_id', $city->id)->where('name', 'Dokki')->exists());

        $size = $this->makeSize();
        $size->update(['sku' => 'RW-SKU']);
        $this->confirm($this->upload($this->csv([['code' => 'rw-1', 'type' => 'product', 'title' => 'Free vitamin', 'product_sku' => 'RW-SKU', 'points_required' => '150']],
            ['code', 'type', 'title', 'product_sku', 'points_required']), 'upsert', 'loyalty_rewards'));
        $reward = \App\Models\LoyaltyReward::where('code', 'RW-1')->sole();
        $this->assertSame([$size->id, 150], [$reward->product_size_id, $reward->points_required]);

        $rows = $this->exportRows(['entity' => 'loyalty_rewards', 'format' => 'csv']);
        $this->assertSame(['RW-1', 'RW-SKU'], [$rows[0]['code'], $rows[0]['product_sku']]);
        foreach (['categories', 'brands', 'suppliers', 'cities', 'areas', 'inventory', 'orders', 'order_items', 'payments', 'refunds', 'loyalty_transactions'] as $entity) {
            $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.exports.store'), ['entity' => $entity, 'format' => 'csv'])->assertRedirect();
            $this->assertSame('completed', ExportRun::latest('id')->first()->status, $entity);
        }
        @unlink(public_path('category/v.png'));
    }

    public function test_history_screens_and_cancellation(): void
    {
        $run = $this->upload($this->csv([$this->row()]));
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.imports.cancel', $run))->assertSessionHas('success');
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->actingAs($this->admin, 'admin')->post(route('dashboard.data.imports.confirm', $run))->assertSessionHas('error');

        $empty = $this->upload($this->csv([], ['foo', 'bar']));
        $this->assertSame('failed', $empty->status);
        $this->assertStringContainsString('Missing columns', $empty->failure_message);

        foreach ([route('dashboard.data.index'), route('dashboard.data.imports.index'), route('dashboard.data.exports.index'), route('dashboard.data.imports.show', $empty)] as $url) {
            $this->actingAs($this->admin, 'admin')->get($url)->assertOk();
        }
        $this->actingAs($this->admin, 'admin')->get(route('dashboard.data.imports.file', [$run, 'original']))->assertOk();
        $this->assertSame(['data.import_upload', 'data.import_cancel', 'data.import_download'], AdminAuditLog::where('subject_id', $run->id)->where('subject_type', 'ImportRun')->orderBy('id')->pluck('action')->all());
    }

    public function test_image_urls_to_private_addresses_are_refused(): void
    {
        app()->forgetInstance(ImageFetcher::class);
        $this->expectException(\DomainException::class);
        app(ImageFetcher::class)->fetch('http://127.0.0.1/admin.png', 'products');
    }
}
