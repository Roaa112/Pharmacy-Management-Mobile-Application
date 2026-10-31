<?php

namespace Tests\Feature\Admin;

use App\Models\Address;
use App\Models\Admin;
use App\Models\Banner;
use App\Models\BloodPressureMeasurement;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Child;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\DeliveryPrice;
use App\Models\FlashSale;
use App\Models\HealthIssue;
use App\Models\OpeningAd;
use App\Models\Order;
use App\Models\ProductSize;
use App\Models\ReturnRequest;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Every admin screen renders with realistic data, plus regressions for the bugs fixed in the redesign.
 */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private Admin $admin;
    private Order $order;
    private User $customer;
    private ProductSize $size;
    private ReturnRequest $return;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create(['name' => 'Sara Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);
        $this->customer = User::factory()->create(['name' => 'Omar Customer']);

        $category = Category::create(['name_ar' => 'فيتامينات', 'name_en' => 'Vitamins', 'image' => 'category/v.png']);
        Category::create(['name_ar' => 'فرعي', 'name_en' => 'Vitamin C', 'image' => 'category/c.png', 'parent_id' => $category->id]);
        $brand = Brand::create(['name_ar' => 'ماركة', 'name_en' => 'Pharma Co', 'image' => 'brands/b.png']);
        HealthIssue::create(['name_ar' => 'سكري', 'name_en' => 'Diabetes', 'image' => 'healthissue/d.png']);

        $this->size = $this->makeSize(price: 25, stock: 3);
        $this->size->product->update(['category_id' => $category->id, 'brand_id' => $brand->id, 'name_en' => 'Vitamin C 500']);

        $address = Address::create(['user_id' => $this->customer->id, 'governorate' => 'Baghdad', 'city' => 'Baghdad', 'street' => 'Main', 'type' => 'home', 'landmark' => 'Near the park']);
        $this->order = $this->makeOrder($this->customer, $this->size, 2, 'completed');
        $this->order->update(['address_id' => $address->id, 'points_earned' => 5, 'notes' => 'Ring twice']);
        $this->return = ReturnRequest::create(['user_id' => $this->customer->id, 'order_id' => $this->order->id, 'reason' => 'Damaged box', 'refund_amount' => 20]);

        $coupon = Coupon::create(['code' => 'WELCOME10', 'discount_value' => 10, 'is_active' => true, 'start_at' => now()->subDay(), 'end_at' => now()->addMonth(), 'usage_limit' => 100]);
        $this->order->update(['coupon_id' => $coupon->id]);
        FlashSale::create(['discount_value' => 15, 'is_active' => true, 'date' => now()->toDateString(), 'time' => '10:00:00']);
        $ruleId = DB::table('discount_rules')->insertGetId(['discount_type' => 'percent', 'discount_value' => 5, 'applies_to_type' => 'brand', 'applies_to_id' => $brand->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]);
        DB::table('discount_rule_targets')->insert(['discount_rule_id' => $ruleId, 'target_type' => 'brand', 'target_id' => $brand->id]);
        Banner::create(['title_en' => 'Summer', 'title_ar' => 'صيف', 'image' => 'banners/s.png', 'link' => url('/api/v1/user/brands'), 'status' => 1, 'order_of_appearance' => 1]);
        OpeningAd::create(['image' => 'OpeningAdS/a.png', 'is_active' => 1]);
        DeliveryPrice::create(['governorate' => 'Baghdad', 'price' => 5]);
        Setting::create(['spend_x' => '10', 'get_y' => '1', 'points_validity_days' => 30, 'phone_number' => '0770000000']);
        ContactMessage::create(['name' => 'Omar', 'email' => 'omar@example.com', 'message' => 'Hello', 'user_id' => $this->customer->id]);
        BloodPressureMeasurement::create(['user_id' => $this->customer->id, 'systolic' => 120, 'diastolic' => 80, 'condition_type' => 'rest', 'measured_at' => now()]);
        Child::create(['user_id' => $this->customer->id, 'child_name' => 'Lina', 'gender' => 'أنثى', 'birth_date' => '2024-01-01']);
        ProductSize::moveStock($this->size->id, 2, StockMovement::TYPE_ADJUSTMENT, ['admin_id' => $this->admin->id, 'reason' => 'Delivery received']);
    }

    public static function pages(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'reports' => ['/dashboard/reports'],
            'reports with range' => ['/dashboard/reports?from=2020-01-01&to=2030-01-01'],
            'orders' => ['/dashboard/orders'],
            'orders filtered' => ['/dashboard/orders?status=completed&search=Omar'],
            'order details' => ['/dashboard/orders/{order}'],
            'returns' => ['/dashboard/returns'],
            'return details' => ['/dashboard/returns/{return}'],
            'customers' => ['/dashboard/customers?search=Omar'],
            'customer profile' => ['/dashboard/customers/{customer}'],
            'points' => ['/dashboard/points'],
            'products' => ['/dashboard/products?search=Vitamin&stock=low'],
            'product create' => ['/dashboard/products/create'],
            'product edit' => ['/dashboard/products/{product}/edit'],
            'categories' => ['/dashboard/categories'],
            'brands' => ['/dashboard/brands'],
            'health issues' => ['/dashboard/health_issues'],
            'inventory' => ['/dashboard/inventory'],
            'inventory low' => ['/dashboard/inventory?stock=low&search=Vitamin'],
            'stock movements' => ['/dashboard/inventory/movements'],
            'stock movements of a size' => ['/dashboard/inventory/movements?size={size}&type=adjustment'],
            'coupons' => ['/dashboard/coupons'],
            'discounts' => ['/dashboard/discounts'],
            'flash sales' => ['/dashboard/flash_sales'],
            'banners' => ['/dashboard/banners'],
            'opening ads' => ['/dashboard/OpeningAd'],
            'delivery prices' => ['/dashboard/delivery_prices'],
            'contact messages' => ['/dashboard/contacts'],
            'settings' => ['/dashboard/settings'],
            'health: body weight' => ['/dashboard/health-results/body-weight'],
            'health: blood sugar' => ['/dashboard/health-results/blood-sugar'],
            'health: blood pressure' => ['/dashboard/health-results/blood-pressure'],
            'health: ovulation' => ['/dashboard/health-results/ovulation'],
            'health: pregnancy' => ['/dashboard/health-results/pregnancy'],
            'health: children' => ['/dashboard/health-results/children'],
        ];
    }

    #[DataProvider('pages')]
    public function test_admin_page_renders(string $uri): void
    {
        $uri = strtr($uri, [
            '{order}' => $this->order->id,
            '{return}' => $this->return->id,
            '{customer}' => $this->customer->id,
            '{product}' => $this->size->product_id,
            '{size}' => $this->size->id,
        ]);

        $this->actingAs($this->admin, 'admin')->get($uri)
            ->assertOk()
            ->assertSee('Sara Admin')                    // layout rendered with the signed-in admin
            ->assertDontSee('adminlte', false);          // no old template left
    }

    public function test_dashboard_numbers_come_from_the_database(): void
    {
        // A canceled order must not count as revenue or best-seller units.
        $this->makeOrder($this->customer, $this->size, 7, 'canceled');

        $response = $this->actingAs($this->admin, 'admin')->get('/dashboard')->assertOk();

        $response->assertViewHas('stats', fn ($s) => (float) $s['revenue'] === 50.0 && $s['orders'] === 2 && $s['pending_returns'] === 1);
        $response->assertViewHas('topProducts', fn ($rows) => (int) $rows->first()->units === 2);
    }

    public function test_logout_signs_the_admin_out(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/logout')->assertRedirect();

        $this->assertGuest('admin');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_signed_in_admin_is_sent_from_login_to_the_dashboard(): void
    {
        $this->actingAs($this->admin, 'admin')->get('/login')->assertRedirect();
    }

    public function test_product_edit_form_keeps_the_attached_flash_sale_selected(): void
    {
        $sale = FlashSale::first();
        $this->size->product->update(['saleable_type' => FlashSale::class, 'saleable_id' => $sale->id]);

        $html = $this->actingAs($this->admin, 'admin')->get("/dashboard/products/{$this->size->product_id}/edit")->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<option value="flash_sale"\s+selected/', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$sale->id.'"\s+selected/', $html);
    }

    public function test_saving_the_product_form_does_not_remove_its_flash_sale(): void
    {
        $sale = FlashSale::first();
        $product = $this->size->product;
        $product->update(['saleable_type' => FlashSale::class, 'saleable_id' => $sale->id]);

        // What the redesigned form submits after editing only the description.
        $this->actingAs($this->admin, 'admin')->from("/dashboard/products/{$product->id}/edit")->put("/dashboard/products/{$product->id}", [
            'name_en' => $product->name_en, 'name_ar' => $product->name_ar, 'description_en' => 'Updated',
            'category_id' => $product->category_id, 'brand_id' => $product->brand_id,
            'promotion_type' => 'flash_sale', 'flash_sale_id' => $sale->id,
            'sizes' => [['id' => $this->size->id, 'size' => 'M', 'price' => 25, 'stock' => 5, 'stock_original' => 5]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(FlashSale::class, $product->fresh()->saleable_type);
        $this->assertSame('Updated', $product->fresh()->description_en);
    }

    public function test_viewing_coupons_does_not_switch_off_scheduled_coupons(): void
    {
        $scheduled = Coupon::create(['code' => 'NEXTWEEK', 'discount_value' => 5, 'is_active' => true, 'start_at' => now()->addWeek(), 'end_at' => now()->addMonth()]);

        $this->actingAs($this->admin, 'admin')->get('/dashboard/coupons')->assertOk()->assertSee('Scheduled');

        $this->assertTrue($scheduled->fresh()->is_active);
    }

    public function test_admin_lists_are_not_cut_at_ten_rows(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            Brand::create(['name_ar' => "ب{$i}", 'name_en' => sprintf('Brand %02d', $i), 'image' => 'brands/x.png']);
        }

        $this->actingAs($this->admin, 'admin')->get('/dashboard/brands')->assertOk()->assertSee('Brand 12')->assertSee('Brand 01');
    }

    public function test_order_page_offers_only_valid_next_statuses(): void
    {
        $pending = $this->makeOrder($this->customer, $this->size, 1, 'pending');

        $completedHtml = $this->actingAs($this->admin, 'admin')->get("/dashboard/orders/{$this->order->id}")->getContent();
        $this->assertStringNotContainsString('name="status"', $completedHtml);

        $this->actingAs($this->admin, 'admin')->get("/dashboard/orders/{$pending->id}")
            ->assertSee('value="confirmed"', false)
            ->assertSee('value="canceled"', false)
            ->assertDontSee('value="pending"', false);
    }

    public function test_customer_profile_does_not_show_health_data(): void
    {
        $this->actingAs($this->admin, 'admin')->get("/dashboard/customers/{$this->customer->id}")
            ->assertOk()
            ->assertSee('Omar Customer')
            ->assertDontSee('Lina')            // child's name
            ->assertDontSee('Systolic');
    }

    public function test_points_row_buttons_are_not_nested_inside_the_bulk_form(): void
    {
        $html = $this->actingAs($this->admin, 'admin')->get('/dashboard/points')->assertOk()->getContent();

        // The bulk form is closed before the table; checkboxes join it through the form attribute.
        $this->assertMatchesRegularExpression('#id="redeem-multiple-form"[^>]*>.*?</form>#s', $html);
        $this->assertStringContainsString('form="redeem-multiple-form"', $html);
        $bulkForm = substr($html, strpos($html, 'id="redeem-multiple-form"'));
        $this->assertStringNotContainsString('<form', substr($bulkForm, 0, strpos($bulkForm, '</form>')));
    }

    public function test_discount_creation_is_validated(): void
    {
        $this->actingAs($this->admin, 'admin')->from('/dashboard/discounts')->post('/dashboard/discounts', [
            'discount_type' => 'percent', 'discount_value' => 150,
        ])->assertSessionHasErrors(['discount_value', 'targets', 'starts_at', 'ends_at']);

        $this->actingAs($this->admin, 'admin')->from('/dashboard/discounts')->post('/dashboard/discounts', [
            'discount_type' => 'percent', 'discount_value' => 20,
            'targets' => [['type' => 'product', 'id' => $this->size->product_id]],
            'starts_at' => now()->toDateTimeString(), 'ends_at' => now()->addWeek()->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('discount_rules', ['discount_type' => 'percent', 'discount_value' => 20]);
    }

    public function test_flash_sale_edit_form_sends_a_time_the_validator_accepts(): void
    {
        $sale = FlashSale::first();

        $html = $this->actingAs($this->admin, 'admin')->get('/dashboard/flash_sales')->getContent();
        $this->assertMatchesRegularExpression('/name="time"\s+value="10:00"/', $html);

        $this->actingAs($this->admin, 'admin')->from('/dashboard/flash_sales')->put("/dashboard/flash_sales/{$sale->id}", [
            'discount_value' => 20, 'is_active' => 1, 'date' => now()->toDateString(), 'time' => '10:00',
        ])->assertSessionHasNoErrors();
    }

    public function test_sidebar_badges_show_real_pending_counts(): void
    {
        $this->makeOrder($this->customer, $this->size, 1, 'pending');

        $html = $this->actingAs($this->admin, 'admin')->get('/dashboard/products')->assertOk()->getContent();

        // Sidebar: Orders and Returns each carry a badge with their pending count.
        $this->assertMatchesRegularExpression('#>Orders</span>\s*<span class="badge[^"]*">1</span>#', $html);
        $this->assertMatchesRegularExpression('#>Returns</span>\s*<span class="badge[^"]*">1</span>#', $html);
    }

    public function test_out_of_stock_badge_counts_sizes_of_listed_products_only(): void
    {
        $this->makeSize(stock: 0);
        $deleted = $this->makeSize(stock: 0);
        $deleted->product->delete();

        $this->actingAs($this->admin, 'admin')->get('/dashboard')
            ->assertViewHas('stats', fn ($s) => $s['out_of_stock'] === 1);
    }
}
