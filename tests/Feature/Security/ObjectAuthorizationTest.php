<?php

namespace Tests\Feature\Security;

use App\Models\Address;
use App\Models\Cart;
use App\Models\Child;
use App\Models\Medication;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Models\Vaccine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

/**
 * A user must never read or change another user's records by changing an ID in the URL/body.
 */
class ObjectAuthorizationTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $attacker;
    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attacker = User::factory()->create();
        $this->victim = User::factory()->create();
        Sanctum::actingAs($this->attacker);
    }

    public function test_cannot_view_or_track_another_users_order(): void
    {
        $order = $this->makeOrder($this->victim, $this->makeSize());

        $this->getJson("/api/user/order-details/{$order->id}")->assertNotFound();
        $this->getJson("/api/user/track-order/{$order->id}")->assertNotFound();
    }

    public function test_can_view_own_order(): void
    {
        $order = $this->makeOrder($this->attacker, $this->makeSize());

        $this->getJson("/api/user/order-details/{$order->id}")->assertOk()->assertJsonPath('order_id', $order->id);
    }

    public function test_cannot_delete_another_users_cart_item(): void
    {
        $item = $this->putInCart($this->victim, $this->makeSize(), 1);

        $this->deleteJson("/api/user/cart/{$item->id}")->assertNotFound();

        $this->assertDatabaseHas('carts', ['id' => $item->id]);
    }

    public function test_cannot_read_change_or_delete_another_users_address(): void
    {
        $address = $this->makeAddress($this->victim);
        $payload = ['governorate' => 'X', 'city' => 'X', 'street' => 'X', 'type' => 'home'];

        $this->getJson("/api/user/addresses/{$address->id}")->assertNotFound();
        $this->putJson("/api/user/addresses/{$address->id}", $payload)->assertNotFound();
        $this->postJson("/api/user/addresses/{$address->id}/toggle-default")->assertNotFound();
        $this->deleteJson("/api/user/addresses/{$address->id}")->assertStatus(400);

        $this->assertSame('Cairo', $address->fresh()->city);
        $this->assertFalse((bool) $address->fresh()->is_default);
    }

    public function test_cannot_checkout_to_another_users_address(): void
    {
        $this->makeDeliveryPrice();
        $address = $this->makeAddress($this->victim);
        $this->putInCart($this->attacker, $this->makeSize(), 1);

        $this->postJson('/api/user/orders', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_cannot_change_or_delete_another_users_medication(): void
    {
        $medication = Medication::create([
            'user_id' => $this->victim->id,
            'name' => 'Insulin',
            'quantity_per_time' => 1,
            'repeat_type' => 'every_day',
        ]);

        $this->postJson("/api/user/medications/{$medication->id}/update", [
            'name' => 'Changed',
            'quantity_per_time' => 2,
            'repeat_type' => 'once',
            'times' => ['08:00:00'],
        ])->assertNotFound();
        $this->postJson("/api/user/medications/{$medication->id}/log", ['time' => '08:00:00', 'status' => 'taken'])
            ->assertNotFound();
        $this->deleteJson('/api/user/medications/remove-day', ['medication_id' => $medication->id, 'day' => 'monday'])
            ->assertNotFound();
        $this->deleteJson("/api/user/medication/{$medication->id}")->assertNotFound();

        $this->assertSame('Insulin', $medication->fresh()->name);
        $this->assertDatabaseCount('medication_logs', 0);
    }

    public function test_cannot_change_another_users_vaccines_or_delete_their_child(): void
    {
        $child = Child::create([
            'user_id' => $this->victim->id,
            'child_name' => 'Sara',
            'gender' => 'أنثى',
            'birth_date' => '2024-01-01',
        ]);
        $vaccine = Vaccine::create([
            'child_id' => $child->id,
            'vaccine_name' => 'BCG',
            'scheduled_date' => '2024-01-08',
            'is_completed' => false,
        ]);

        $this->patchJson("/api/user/health/vaccines/{$vaccine->id}/complete")->assertNotFound();
        $this->deleteJson("/api/user/health/child/{$child->id}")->assertNotFound();

        $this->assertFalse((bool) $vaccine->fresh()->is_completed);
        $this->assertDatabaseHas('children', ['id' => $child->id]);
    }

    public function test_cannot_request_a_return_for_another_users_order(): void
    {
        $order = $this->makeOrder($this->victim, $this->makeSize());

        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'damaged'])->assertNotFound();

        $this->assertSame(0, ReturnRequest::count());
    }

    public function test_return_refund_cannot_exceed_order_total(): void
    {
        $order = $this->makeOrder($this->attacker, $this->makeSize(price: 100));

        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'damaged', 'refund_amount' => 1000])
            ->assertStatus(422);
        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'damaged', 'refund_amount' => -5])
            ->assertStatus(422);
        $this->postJson('/api/user/returns', ['order_id' => $order->id, 'reason' => 'damaged', 'refund_amount' => 100])
            ->assertCreated();
    }

    public function test_deleting_an_address_keeps_the_orders_that_used_it(): void
    {
        $address = $this->makeAddress($this->attacker);
        $order = $this->makeOrder($this->attacker, $this->makeSize());
        $order->update(['address_id' => $address->id]);

        $this->deleteJson("/api/user/addresses/{$address->id}")->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'address_id' => null]);
    }
}
