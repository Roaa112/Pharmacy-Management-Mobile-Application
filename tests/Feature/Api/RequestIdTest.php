<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Every API and dashboard response carries the id its log lines are written with. */
class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_carry_a_request_id_and_keep_a_valid_incoming_one(): void
    {
        $generated = $this->getJson('/api/v1/user/brands')->assertOk()->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $generated);

        $this->getJson('/api/v1/user/brands', ['X-Request-Id' => 'app-1234-abcd'])->assertHeader('X-Request-Id', 'app-1234-abcd');
        $this->postJson('/api/webhooks/payments/paymob', [], ['X-Request-Id' => 'paymob-retry-77'])->assertHeader('X-Request-Id', 'paymob-retry-77');

        // Anything else is replaced, never echoed back (no header injection or log forging).
        $replaced = $this->getJson('/api/v1/user/brands', ['X-Request-Id' => "bad id\r\nX-Evil: 1"])->headers->get('X-Request-Id');
        $this->assertNotSame("bad id\r\nX-Evil: 1", $replaced);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $replaced);

        $this->get('/login')->assertHeader('X-Request-Id');
    }
}
