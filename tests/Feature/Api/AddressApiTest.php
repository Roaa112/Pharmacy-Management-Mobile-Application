<?php

namespace Tests\Feature\Api;

use App\Models\Address;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddressApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'governorate' => 'Cairo',
            'city' => 'Cairo',
            'street' => 'Main street',
            'building' => '12',
            'type' => 'home',
        ], $overrides);
    }

    private function create(array $overrides = []): int
    {
        return $this->postJson('/api/user/addresses', $this->payload($overrides))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->json('data.0.id');
    }

    public function test_address_crud(): void
    {
        $id = $this->create();
        $this->assertSame($this->customer->id, Address::find($id)->user_id);

        $this->getJson("/api/user/addresses/{$id}")->assertOk()->assertJsonPath('data.0.street', 'Main street');

        $this->putJson("/api/user/addresses/{$id}", $this->payload(['street' => 'Second street']))
            ->assertOk()
            ->assertJsonPath('data.0.street', 'Second street');

        $this->getJson('/api/user/addresses')->assertOk()->assertJsonPath('count', 1);

        $this->deleteJson("/api/user/addresses/{$id}")->assertOk()->assertJsonPath('status', 'success');
        $this->assertNull(Address::find($id));
        $this->getJson("/api/user/addresses/{$id}")->assertNotFound();
    }

    public function test_address_input_is_validated(): void
    {
        $this->postJson('/api/user/addresses', $this->payload(['type' => 'castle']))->assertStatus(422)->assertJsonValidationErrors('type');
        $this->postJson('/api/user/addresses', $this->payload(['city' => null]))->assertStatus(422)->assertJsonValidationErrors('city');
        $this->postJson('/api/user/addresses', $this->payload(['street' => str_repeat('a', 256)]))->assertStatus(422);

        $this->assertSame(0, Address::count());
    }

    public function test_the_user_id_in_the_payload_is_ignored(): void
    {
        $other = User::factory()->create();

        $id = $this->create(['user_id' => $other->id]);

        $this->assertSame($this->customer->id, Address::find($id)->user_id);
    }

    public function test_only_one_address_is_default(): void
    {
        $first = $this->create(['is_default' => true]);
        $second = $this->create(['is_default' => true]);

        $this->assertFalse((bool) Address::find($first)->is_default);
        $this->assertTrue((bool) Address::find($second)->is_default);

        $this->putJson("/api/user/addresses/{$first}", $this->payload(['is_default' => true]))->assertOk();
        $this->assertSame([$first], Address::where('user_id', $this->customer->id)->where('is_default', true)->pluck('id')->all());

        $this->postJson("/api/user/addresses/{$second}/toggle-default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertSame([$second], Address::where('user_id', $this->customer->id)->where('is_default', true)->pluck('id')->all());

        $this->postJson("/api/user/addresses/{$second}/toggle-default")->assertOk()->assertJsonPath('data.is_default', false);
        $this->assertSame(0, Address::where('is_default', true)->count());
    }

    public function test_setting_a_default_does_not_touch_other_users_addresses(): void
    {
        $other = User::factory()->create();
        $theirs = Address::create(['user_id' => $other->id, 'governorate' => 'Giza', 'city' => 'Giza', 'street' => 'x', 'type' => 'home', 'is_default' => true]);

        $this->create(['is_default' => true]);

        $this->assertTrue((bool) $theirs->fresh()->is_default);
    }

    public function test_listing_adds_the_pharmacy_pickup_point(): void
    {
        Setting::create(['map_location_url' => 'https://maps.example.com/pharmacy']);
        $this->create();

        $data = $this->getJson('/api/user/addresses')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('pharmacy', end($data)['type']);
        $this->assertTrue(end($data)['is_default']);
    }

    public function test_another_users_address_cannot_be_read_changed_toggled_or_deleted(): void
    {
        $other = User::factory()->create();
        $theirs = Address::create(['user_id' => $other->id, 'governorate' => 'Giza', 'city' => 'Giza', 'street' => 'Theirs', 'type' => 'home']);

        $this->getJson("/api/user/addresses/{$theirs->id}")->assertNotFound();
        $this->putJson("/api/user/addresses/{$theirs->id}", $this->payload())->assertNotFound();
        $this->postJson("/api/user/addresses/{$theirs->id}/toggle-default")->assertNotFound();
        $this->deleteJson("/api/user/addresses/{$theirs->id}")->assertStatus(400);
        $this->assertSame([], $this->getJson('/api/user/addresses')->json('data'));

        $this->assertSame('Theirs', $theirs->fresh()->street);
        $this->assertFalse((bool) $theirs->fresh()->is_default);
    }
}
