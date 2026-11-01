<?php

namespace Tests\Feature\Orders;

use App\Models\Admin;
use App\Models\Medication;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Modules\Admin\SignedFileController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class PaymentsAndFilesTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function checkout(array $data)
    {
        $this->putInCart($this->user, $this->makeSize(price: 10), 1);

        return $this->post('/api/user/orders', ['address_id' => -1] + $data, ['Accept' => 'application/json']);
    }

    public function test_payment_methods_come_from_the_database(): void
    {
        $this->checkout(['payment_method' => 'bitcoin'])->assertStatus(422)->assertJsonValidationErrors('payment_method');

        // A legacy method stays refused even if someone flips it back on in the database.
        PaymentMethod::where('code', 'zaincash')->update(['is_active' => true]);
        $this->checkout(['payment_method' => 'zaincash', 'payment_image' => UploadedFile::fake()->image('r.jpg')])->assertStatus(422);

        // A new cash-on-delivery variant can be added as data.
        PaymentMethod::create(['code' => 'cod_card', 'provider' => 'cod', 'name_ar' => 'بطاقة', 'name_en' => 'Card on delivery', 'is_active' => true]);
        $this->checkout(['payment_method' => 'cod_card'])->assertCreated()->assertJsonPath('payment_status', 'unpaid');
        $this->assertSame(PaymentMethod::where('code', 'cod_card')->value('id'), Order::latest('id')->first()->payment_method_id);

        // Any other provider type cannot be offered.
        PaymentMethod::create(['code' => 'other', 'provider' => 'manual_transfer', 'name_ar' => 'x', 'name_en' => 'Other', 'is_active' => true]);
        $this->checkout(['payment_method' => 'other'])->assertStatus(422);
    }

    /** Receipts now exist only on historical (ZainCash / Qi Card) orders; they stay private and readable. */
    public function test_historical_receipts_stay_private_and_readable(): void
    {
        $id = $this->makeHistoricalZainCashOrder($this->user)->id;
        $path = Order::find($id)->payment_image;
        Storage::disk('local')->put($path, 'receipt');
        Storage::disk('public')->assertMissing($path);

        // The owner can fetch it with their token; another customer cannot.
        $this->get("/api/user/files/payment-receipt/{$id}")->assertOk();
        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/user/files/payment-receipt/{$id}")->assertNotFound();

        // Dashboard: only admins with the payments permission.
        $agent = Admin::create(['name' => 'Agent', 'email' => 'agent@example.com', 'password' => 'secret-password']);
        $agent->forceFill(['role' => 'orders_agent'])->save();
        $this->actingAs($agent, 'admin')->get(route('dashboard.files.paymentReceipt', $id))->assertForbidden();
        $super = Admin::create(['name' => 'Super', 'email' => 'super@example.com', 'password' => 'secret-password']);
        $this->actingAs($super, 'admin')->get(route('dashboard.files.paymentReceipt', $id))->assertOk();
    }

    public function test_signed_links_work_without_a_token_and_cannot_be_forged(): void
    {
        Storage::disk('local')->put('return_images/a.jpg', 'img');
        $order = $this->makeOrder($this->user, $this->makeSize());
        $return = ReturnRequest::create(['user_id' => $this->user->id, 'order_id' => $order->id, 'reason' => 'x']);
        $image = $return->images()->create(['path' => 'return_images/a.jpg']);

        $url = $image->full_url;
        $this->assertStringContainsString('signature=', $url);

        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk();
        $this->get(str_replace('return-image/'.$image->id, 'return-image/'.($image->id + 1), $url))->assertForbidden();
        $this->get(route('files.signed', ['type' => 'return-image', 'id' => $image->id]))->assertForbidden();

        $this->travel(SignedFileController::TTL_MINUTES + 1)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_legacy_public_files_are_still_found_until_moved(): void
    {
        Storage::disk('public')->put('payments/old.jpg', 'old');
        $order = $this->makeOrder($this->user, $this->makeSize());
        $order->forceFill(['payment_image' => 'payments/old.jpg'])->save();

        $this->get("/api/user/files/payment-receipt/{$order->id}")->assertOk();

        $this->artisan('files:make-private')->assertSuccessful();
        Storage::disk('local')->assertExists('payments/old.jpg');
        Storage::disk('public')->assertExists('payments/old.jpg');

        $this->artisan('files:make-private --delete-public')->assertSuccessful();
        Storage::disk('public')->assertMissing('payments/old.jpg');
        $this->get("/api/user/files/payment-receipt/{$order->id}")->assertOk();
    }

    public function test_medication_photos_are_private_and_served_by_signed_link(): void
    {
        $id = $this->post('/api/user/medication', [
            'name' => 'Metformin', 'quantity_per_time' => 1, 'repeat_type' => 'every_day', 'times' => ['08:00'],
            'image' => UploadedFile::fake()->image('pill.jpg'),
        ], ['Accept' => 'application/json'])->assertSuccessful()->json('data.id') ?? Medication::latest('id')->value('id');

        $medication = Medication::findOrFail($id);
        Storage::disk('local')->assertExists($medication->image_path);
        Storage::disk('public')->assertMissing($medication->image_path);

        $url = collect($this->getJson('/api/user/medication')->json())->pluck('medications')->flatten(1)->firstWhere('id', $id)['image_url'];
        $this->assertStringContainsString('signature=', $url);
    }
}
