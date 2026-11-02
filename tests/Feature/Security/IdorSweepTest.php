<?php

namespace Tests\Feature\Security;

use App\Models\Medication;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * Final audit: a second customer replays every owner-scoped request with the first customer's
 * resource ids. Every one must look as if the resource did not exist, and nothing may change.
 */
class IdorSweepTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public function test_another_customer_cannot_reach_or_change_any_owned_resource(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $address = $this->makeAddress($owner);
        $size = $this->makeSize(price: 30, stock: 5);
        $order = $this->makeOrder($owner, $size, 1, 'completed');
        $order->forceFill(['payment_image' => 'payments/receipt.jpg', 'payment_method' => Payment::PROVIDER_PAYMOB])->save();
        Storage::disk('local')->put('payments/receipt.jpg', 'receipt');
        $return = ReturnRequest::create(['user_id' => $owner->id, 'order_id' => $order->id, 'reason' => 'Damaged', 'refund_amount' => 10]);
        $image = $return->images()->create(['path' => 'return_images/a.jpg']);
        Storage::disk('local')->put('return_images/a.jpg', 'photo');
        $medication = Medication::create(['user_id' => $owner->id, 'name' => 'Private medicine', 'repeat_type' => 'every_day', 'image_path' => 'medications/m.jpg']);
        Storage::disk('local')->put('medications/m.jpg', 'photo');
        $cart = $this->putInCart($owner, $size, 1);

        Sanctum::actingAs($attacker);

        $requests = [
            ['GET', "/api/user/order-details/{$order->id}", 404],
            ['GET', "/api/user/track-order/{$order->id}", 404],
            ['POST', "/api/user/orders/{$order->id}/cancel", 404],
            ['POST', "/api/user/orders/{$order->id}/pay", 404],
            ['GET', "/api/user/orders/{$order->id}/payment", 404],
            ['GET', "/api/user/returns/{$return->id}", 404],
            ['POST', "/api/user/returns/{$return->id}/cancel", 404],
            ['POST', '/api/user/returns', 404, ['order_id' => $order->id, 'reason' => 'Mine now']],
            ['GET', "/api/user/files/payment-receipt/{$order->id}", 404],
            ['GET', "/api/user/files/return-image/{$image->id}", 404],
            ['GET', "/api/user/files/medication/{$medication->id}", 404],
            ['GET', "/api/user/addresses/{$address->id}", 404],
            ['POST', "/api/user/addresses/{$address->id}/toggle-default", 404],
            ['POST', '/api/user/delivery/quote', 404, ['address_id' => $address->id]],
            ['DELETE', "/api/user/cart/{$cart->id}", 404],
            ['POST', "/api/user/medications/{$medication->id}/log", 404, ['date' => now()->toDateString(), 'time' => '08:00', 'status' => 'taken']],
            ['POST', "/api/user/medications/{$medication->id}/update", 404, ['name' => 'Renamed']],
            ['DELETE', "/api/user/medication/{$medication->id}", 404],
            ['DELETE', '/api/user/medications/remove-day', 404, ['medication_id' => $medication->id, 'day' => 'monday']],
            ['PUT', "/api/user/update/{$owner->id}", 403, ['name' => 'Taken over', 'email' => 'attacker-new@example.com', 'phone' => '0100000099']],
        ];

        foreach ($requests as $request) {
            [$method, $uri, $expected] = $request;
            $status = $this->json($method, $uri, $request[3] ?? [])->getStatusCode();
            $this->assertSame($expected, $status, "{$method} {$uri} answered {$status}");
        }

        // Lists only ever show the caller's own records.
        $this->getJson('/api/user/returns')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/user/addresses')->assertOk()->assertJsonMissing(['id' => $address->id]);

        // Nothing changed.
        $this->assertSame(['completed', 'pending', 1, 'Private medicine', $owner->name],
            [$order->fresh()->status, $return->fresh()->status, $cart->fresh()?->product_quantity, $medication->fresh()->name, $owner->fresh()->name]);
        $this->assertSame(1, ReturnRequest::count());
        $this->assertSame(0, Payment::where('order_id', $order->id)->count());
    }
}
