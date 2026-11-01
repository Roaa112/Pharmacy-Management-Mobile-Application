<?php

namespace Tests\Feature\Delivery;

use App\Models\DeliveryProvider;
use App\Models\Order;
use App\Models\ProviderStatusMap;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShop;
use Tests\TestCase;

class DeliveryWebhookTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    private const SECRET = 'a-long-enough-webhook-secret';

    private DeliveryProvider $provider;
    private Shipment $shipment;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = DeliveryProvider::create([
            'name' => 'Fast', 'code' => 'fast', 'type' => 'external_api', 'adapter' => 'signed_webhook', 'webhook_secret' => self::SECRET,
        ]);
        ProviderStatusMap::create(['delivery_provider_id' => $this->provider->id, 'external_status' => 'PICKED', 'internal_status' => 'picked_up']);
        ProviderStatusMap::create(['delivery_provider_id' => $this->provider->id, 'external_status' => 'DONE', 'internal_status' => 'delivered']);

        $this->order = $this->makeOrder(User::factory()->create(), $this->makeSize(), status: 'confirmed');
        $this->shipment = Shipment::create([
            'order_id' => $this->order->id, 'delivery_provider_id' => $this->provider->id, 'status' => 'assigned',
            'tracking_number' => 'TRK-1', 'external_reference' => 'EXT-1',
        ]);
    }

    private function send(array $body, ?string $secret = self::SECRET)
    {
        $json = json_encode($body);

        return $this->call('POST', '/api/webhooks/delivery/fast', [], [], [], array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => $secret ? hash_hmac('sha256', $json, $secret) : null,
        ]), $json);
    }

    public function test_a_bad_or_missing_signature_is_refused_and_nothing_is_stored(): void
    {
        $this->send(['event_id' => '1', 'reference' => 'EXT-1', 'status' => 'DONE'], 'wrong-secret')->assertStatus(401);
        $this->send(['event_id' => '1', 'reference' => 'EXT-1', 'status' => 'DONE'], null)->assertStatus(401);

        $this->assertSame(0, ShipmentEvent::count());
        $this->assertSame('confirmed', $this->order->fresh()->status);
    }

    public function test_mapped_statuses_update_shipment_and_order_and_repeats_are_ignored(): void
    {
        $this->send(['event_id' => 'e1', 'reference' => 'EXT-1', 'status' => 'PICKED'])->assertOk()->assertJsonPath('results.0.result', 'applied');
        $this->assertSame('on_delivery', $this->order->fresh()->status);

        $this->send(['events' => [
            ['event_id' => 'e2', 'tracking_number' => 'TRK-1', 'status' => 'DONE', 'occurred_at' => '2026-11-01T10:00:00Z'],
            ['event_id' => 'e2', 'tracking_number' => 'TRK-1', 'status' => 'DONE'],
        ]])->assertOk()->assertJsonPath('results.1.result', 'duplicate');

        $this->assertSame('completed', $this->order->fresh()->status);
        $this->assertSame(2, ShipmentEvent::where('source', 'webhook')->count());
        $this->assertSame('provider', $this->order->statusHistory()->get()->last()->actor_type);
    }

    public function test_unmapped_status_is_kept_flagged_and_does_not_touch_the_order(): void
    {
        $this->send(['event_id' => 'u1', 'reference' => 'EXT-1', 'status' => 'AT_HUB_7'])->assertOk()->assertJsonPath('results.0.result', 'unmapped_status');

        $event = ShipmentEvent::where('raw_status', 'AT_HUB_7')->firstOrFail();
        $this->assertNull($event->status);
        $this->assertFalse($event->applied);
        $this->assertTrue($this->shipment->fresh()->needs_attention);
        $this->assertSame('assigned', $this->shipment->fresh()->status->value);
        $this->assertSame('confirmed', $this->order->fresh()->status);
    }

    public function test_unknown_shipment_and_inactive_provider(): void
    {
        $this->send(['event_id' => 'x', 'reference' => 'NOT-OURS', 'status' => 'DONE'])->assertOk()->assertJsonPath('results.0.result', 'unknown_shipment');

        $this->provider->update(['is_active' => false]);
        $this->send(['event_id' => 'y', 'reference' => 'EXT-1', 'status' => 'DONE'])->assertNotFound();
        $this->assertSame('confirmed', $this->order->fresh()->status);
    }

    public function test_a_manual_provider_never_accepts_webhooks(): void
    {
        DeliveryProvider::create(['name' => 'Team', 'code' => 'team', 'type' => 'internal', 'adapter' => 'manual', 'webhook_secret' => self::SECRET]);
        $json = json_encode(['event_id' => '1', 'reference' => 'EXT-1', 'status' => 'DONE']);

        $this->call('POST', '/api/webhooks/delivery/team', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $json, self::SECRET)], $json)
            ->assertStatus(401);
    }
}
