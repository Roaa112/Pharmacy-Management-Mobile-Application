<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role, bool $active = true): Admin
    {
        $admin = Admin::create(['name' => ucfirst($role), 'email' => $role.uniqid().'@example.com', 'password' => 'secret-password']);
        $admin->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $admin;
    }

    public static function access(): array
    {
        return [
            // [role, url, allowed]
            'agent: orders' => ['orders_agent', '/dashboard/orders', true],
            'agent: health records' => ['orders_agent', '/dashboard/health-results/pregnancy', false],
            'agent: settings' => ['orders_agent', '/dashboard/settings', false],
            'agent: refunds' => ['orders_agent', '/dashboard/returns', false],
            'agent: shipments' => ['orders_agent', '/dashboard/delivery/shipments', true],
            'manager: health records' => ['manager', '/dashboard/health-results/ovulation', false],
            'manager: admins' => ['manager', '/dashboard/admins', false],
            'manager: settings' => ['manager', '/dashboard/settings', true],
            'specialist: health records' => ['health_specialist', '/dashboard/health-results/children', true],
            'specialist: vaccine schedule' => ['health_specialist', '/dashboard/health-content/vaccines', true],
            'specialist: orders' => ['health_specialist', '/dashboard/orders', false],
            'editor: vaccine schedule' => ['content_editor', '/dashboard/health-content/vaccines', true],
            'editor: health records' => ['content_editor', '/dashboard/health-results/blood-sugar', false],
            'accountant: refunds' => ['accountant', '/dashboard/returns', true],
            'accountant: reports' => ['accountant', '/dashboard/reports', true],
            'accountant: products' => ['accountant', '/dashboard/products', false],
            'super admin: everything' => ['super_admin', '/dashboard/health-results/pregnancy', true],
        ];
    }

    #[DataProvider('access')]
    public function test_roles_only_reach_their_pages(string $role, string $url, bool $allowed): void
    {
        $response = $this->actingAs($this->admin($role), 'admin')->get($url);

        $allowed ? $response->assertOk() : $response->assertForbidden();
    }

    public function test_existing_admins_default_to_super_admin_and_the_menu_follows_the_role(): void
    {
        $legacy = Admin::create(['name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => 'secret-password']);
        $this->assertSame(AdminRole::SuperAdmin, $legacy->fresh()->roleEnum());

        $this->actingAs($this->admin('orders_agent'), 'admin')->get('/dashboard')
            ->assertOk()->assertDontSee('Health data')->assertDontSee('Admins &amp; roles', false)->assertSee('Shipments');
    }

    public function test_viewing_health_records_and_changing_settings_are_audited(): void
    {
        $specialist = $this->admin('health_specialist');
        $this->actingAs($specialist, 'admin')->get('/dashboard/health-results/blood-pressure?search=Sara')->assertOk();

        $log = AdminAuditLog::where('action', 'health.view')->firstOrFail();
        $this->assertSame($specialist->id, $log->admin_id);
        $this->assertSame('blood_pressure', $log->changes['page']);

        $manager = $this->admin('manager');
        $this->actingAs($manager, 'admin')->put(route('dashboard.settings.update', Setting::current()), ['low_stock_threshold' => 8])->assertRedirect();
        $this->assertSame(8, AdminAuditLog::where('action', 'settings.update')->firstOrFail()->changes['after']['low_stock_threshold']);

        $this->actingAs($this->admin('super_admin'), 'admin')->get('/dashboard/audit-log')->assertOk()->assertSee('health.view');
    }

    public function test_deactivated_admin_is_signed_out_and_cannot_sign_in(): void
    {
        $admin = $this->admin('super_admin', active: false);

        $this->actingAs($admin, 'admin')->get('/dashboard')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-password']);
        $this->assertGuest('admin');
    }

    public function test_admin_management_keeps_one_super_admin_and_role_is_not_mass_assignable(): void
    {
        $super = $this->admin('super_admin');

        $this->actingAs($super, 'admin')->put(route('dashboard.admins.update', $super), ['name' => 'X', 'role' => 'manager', 'is_active' => 1])
            ->assertSessionHas('error');
        $this->assertSame('super_admin', $super->fresh()->role);

        $this->actingAs($super, 'admin')->post(route('dashboard.admins.store'), [
            'name' => 'Nurse', 'email' => 'nurse@example.com', 'password' => 'long-enough-pw', 'role' => 'health_specialist',
        ])->assertRedirect();
        $this->assertSame('health_specialist', Admin::where('email', 'nurse@example.com')->value('role'));

        $this->assertNotContains('role', (new Admin())->getFillable());
        $this->actingAs($this->admin('manager'), 'admin')->get('/dashboard/admins')->assertForbidden();
    }

    public function test_settings_are_a_single_row(): void
    {
        $admin = $this->admin('super_admin');
        Setting::current();

        $this->actingAs($admin, 'admin')->post(route('dashboard.settings.store'), ['phone_number' => '123'])->assertRedirect();
        $this->actingAs($admin, 'admin')->post(route('dashboard.settings.store'), ['phone_number' => '456'])->assertRedirect();

        $this->assertSame(1, Setting::count());
        $this->assertSame('456', Setting::current()->phone_number);
        $this->actingAs($admin, 'admin')->delete('/dashboard/settings/'.Setting::current()->id)->assertStatus(405);
    }
}
