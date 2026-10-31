<?php

namespace Tests\Feature\Security;

use App\Models\Admin;
use App\Models\Coupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsShop;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoutesTest extends TestCase
{
    use RefreshDatabase, BuildsShop;

    public static function protectedRoutes(): array
    {
        return [
            ['get', '/dashboard/coupons'],
            ['post', '/dashboard/coupons'],
            ['get', '/dashboard/products'],
            ['post', '/dashboard/products'],
            ['get', '/dashboard/discounts'],
            ['get', '/dashboard/settings'],
            ['get', '/dashboard/categories'],
            ['get', '/dashboard/OpeningAd'],
            ['get', '/dashboard/returns'],
            ['post', '/dashboard/returns/1/accept'],
            ['put', '/dashboard/orders/1/update-status'],
            ['patch', '/dashboard/points/1/redeem'],
            ['get', '/dashboard/points'],
            ['get', '/dashboard/health-results/blood-pressure'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_guests_cannot_reach_dashboard_routes(string $method, string $uri): void
    {
        $this->{$method}($uri)->assertRedirect('/login');
    }

    public function test_guest_cannot_create_a_coupon(): void
    {
        $this->post('/dashboard/coupons', [
            'code' => 'FREE100',
            'discount_value' => 100,
            'start_at' => now(),
            'end_at' => now()->addYear(),
        ])->assertRedirect('/login');

        $this->assertSame(0, Coupon::count());
    }

    public function test_api_user_cannot_use_admin_routes(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder($user, $this->makeSize());

        $this->actingAs($user, 'web')
            ->put("/dashboard/orders/{$order->id}/update-status", ['status' => 'completed'])
            ->assertRedirect('/login');

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_admin_can_reach_dashboard(): void
    {
        $admin = Admin::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-password']);

        $this->actingAs($admin, 'admin')->get('/dashboard/points')->assertOk();
    }

    public function test_public_registration_of_web_accounts_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
