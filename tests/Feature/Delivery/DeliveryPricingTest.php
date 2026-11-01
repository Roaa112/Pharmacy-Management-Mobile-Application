<?php

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Area;
use App\Models\City;
use App\Models\Country;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use App\Modules\Delivery\Services\DeliveryQuoteService;
use App\Support\StoreClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class DeliveryPricingTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function quote(Address $address, float $subtotal = 0)
    {
        return app(DeliveryQuoteService::class)->quote($address, (int) round($subtotal * 100));
    }

    public function test_city_names_match_exactly_never_by_substring(): void
    {
        $this->makeDeliveryPrice('Port Said', 90);

        // "Port" used to match "Port Said" through LIKE %Port%.
        $this->assertFalse($this->quote($this->makeAddress($this->user, 'Port'))->ok());
        $this->assertSame(9000, $this->quote($this->makeAddress($this->user, 'Port Said'))->feeMinor);
    }

    public function test_legacy_address_is_linked_by_governorate_and_remembered(): void
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $address = Address::create(['user_id' => $this->user->id, 'governorate' => 'Cairo', 'city' => 'Nasr City', 'street' => 'x', 'type' => 'home']);

        $this->assertSame(500, $this->quote($address)->feeMinor);
        $this->assertSame(City::named('Cairo')->value('id'), $address->fresh()->city_id);
    }

    public function test_area_zone_wins_over_the_whole_city_zone(): void
    {
        $this->makeDeliveryPrice('Cairo', 5);
        $city = City::named('Cairo')->first();
        $area = Area::create(['name' => 'Maadi', 'city_id' => $city->id]);
        $zone = DeliveryZone::create(['country_id' => Country::default()->id, 'name' => 'Maadi express']);
        $zone->locations()->create(['city_id' => $city->id, 'area_id' => $area->id]);
        $zone->rates()->create(['fee' => 2]);

        $inArea = Address::create(['user_id' => $this->user->id, 'governorate' => 'Cairo', 'city' => 'Maadi', 'city_id' => $city->id, 'area_id' => $area->id, 'street' => 'x', 'type' => 'home']);
        $elsewhere = Address::create(['user_id' => $this->user->id, 'governorate' => 'Cairo', 'city' => 'Heliopolis', 'city_id' => $city->id, 'street' => 'x', 'type' => 'home']);

        $this->assertSame(200, $this->quote($inArea)->feeMinor);
        $this->assertSame(500, $this->quote($elsewhere)->feeMinor);
    }

    public function test_free_delivery_threshold_minimum_order_priority_and_dates(): void
    {
        $rate = $this->makeDeliveryPrice('Giza', 10, ['free_over_amount' => 100, 'min_order_amount' => 20]);
        $address = $this->makeAddress($this->user, 'Giza');

        $this->assertSame('below_minimum', $this->quote($address, 19.99)->error);
        $this->assertSame(1000, $this->quote($address, 20)->feeMinor);
        $this->assertSame(0, $this->quote($address, 100)->feeMinor);
        $this->assertTrue($this->quote($address, 100)->freeDelivery);

        // A higher-priority promotional rate applies only inside its dates (store clock).
        Carbon::setTestNow('2026-11-10 12:00:00');
        $rate->zone->rates()->create(['fee' => 3, 'priority' => 10, 'starts_at' => StoreClock::now()->subHour(), 'ends_at' => StoreClock::now()->addHour()]);
        $this->assertSame(300, $this->quote($address, 50)->feeMinor);
        Carbon::setTestNow('2026-11-10 15:00:00');
        $this->assertSame(1000, $this->quote($address, 50)->feeMinor);
        Carbon::setTestNow();

        $rate->zone->update(['is_active' => false]);
        $this->assertSame('city_not_covered', $this->quote($address, 50)->error);
    }

    public function test_checkout_charges_the_quoted_fee_snapshots_the_address_and_creates_a_shipment(): void
    {
        $this->makeDeliveryPrice('Cairo', 7.5);
        $address = $this->makeAddress($this->user);
        $size = $this->makeSize(price: 40, stock: 5);
        $this->putInCart($this->user, $size, 1);

        $id = $this->postJson('/api/user/orders', ['address_id' => $address->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('delivery_fee', 7.5)
            ->assertJsonPath('total_price', 47.5)
            ->json('order_id');

        $order = Order::find($id);
        $this->assertSame('Main street', $order->shipping_address['street']);

        $shipment = Shipment::where('order_id', $id)->firstOrFail();
        $this->assertSame('pending', $shipment->status->value);
        $this->assertEquals(47.5, $shipment->cod_amount);
        $this->assertEquals(7.5, $shipment->fee_charged);

        // Editing the address later does not change the order.
        $address->update(['street' => 'Moved away']);
        $this->getJson("/api/user/order-details/{$id}")->assertOk()->assertJsonPath('address.street', 'Main street');
    }

    public function test_checkout_refuses_an_uncovered_city_and_below_minimum_orders(): void
    {
        $size = $this->makeSize(price: 10, stock: 5);
        $this->putInCart($this->user, $size, 1);

        $this->postJson('/api/user/orders', ['address_id' => $this->makeAddress($this->user, 'Nowhere')->id, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'city_not_covered');

        $this->makeDeliveryPrice('Aswan', 5, ['min_order_amount' => 50]);
        $this->postJson('/api/user/orders', ['address_id' => $this->makeAddress($this->user, 'Aswan')->id, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonPath('code', 'below_minimum')->assertJsonPath('minimum_order', 50);

        $this->assertSame(0, Order::count());
    }

    public function test_quote_api_and_legacy_delivery_prices_endpoint(): void
    {
        $this->makeDeliveryPrice('Luxor', 6, ['eta_min_days' => 1, 'eta_max_days' => 2]);
        $address = $this->makeAddress($this->user, 'Luxor');

        $this->postJson('/api/user/delivery/quote', ['address_id' => $address->id])
            ->assertOk()->assertJsonPath('delivery_fee', 6)->assertJsonPath('eta_max_days', 2);
        $this->postJson('/api/user/delivery/quote', ['address_id' => $this->makeAddress(User::factory()->create(), 'Luxor')->id])->assertNotFound();

        // The old endpoint keeps its row shape.
        $this->getJson('/api/v1/user/delivary_prices')
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.0.governorate', 'Luxor')
            ->assertJsonPath('data.0.price', '6.00');
    }

    public function test_location_and_payment_method_lists(): void
    {
        $city = City::create(['name' => 'Alexandria', 'country_id' => Country::default()->id]);
        Area::create(['name' => 'Center', 'city_id' => $city->id]);

        // Egypt only: there is no country list to choose from.
        $this->getJson('/api/v1/user/locations/countries')->assertNotFound();
        $this->getJson('/api/v1/user/locations/cities')->assertOk()->assertJsonFragment(['name' => 'Alexandria']);
        $this->getJson('/api/v1/user/locations/areas?city_id='.$city->id)->assertOk()->assertJsonFragment(['name' => 'Center']);

        // Only cash on delivery (Paymob is not configured in tests); the retired wallets are gone.
        $codes = collect($this->getJson('/api/v1/user/payment-methods')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['cash'], $codes);
    }

    public function test_address_accepts_location_ids_that_belong_together(): void
    {
        $city = City::create(['name' => 'Alexandria', 'country_id' => Country::default()->id]);
        $other = City::create(['name' => 'Suez', 'country_id' => Country::default()->id]);
        $area = Area::create(['name' => 'Center', 'city_id' => $city->id]);
        $payload = ['governorate' => 'Alexandria', 'city' => 'Center', 'street' => 's', 'type' => 'home', 'city_id' => $city->id];

        $this->postJson('/api/user/addresses', $payload + ['area_id' => $area->id])->assertSuccessful();
        $this->postJson('/api/user/addresses', array_merge($payload, ['city_id' => $other->id, 'area_id' => $area->id]))->assertStatus(422);
        $this->assertSame($area->id, Address::latest('id')->first()->area_id);
    }
}
