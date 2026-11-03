<?php

namespace Tests\Feature\Security;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Modules\Admin\Authorization\PermissionRegistry;
use App\Modules\Admin\Authorization\RbacSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role, bool $active = true): Admin
    {
        $admin = Admin::create(['name' => ucfirst($role), 'email' => $role.uniqid().'@example.com', 'password' => 'secret-password']);
        $admin->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $admin->fresh();
    }

    private function role(string $name, array $permissions, bool $active = true): Role
    {
        $role = new Role(['name' => $name, 'is_active' => $active]);
        $role->slug = \Illuminate\Support\Str::slug($name, '_');
        $role->save();
        $role->permissions()->sync(Permission::whereIn('slug', $permissions)->pluck('id'));

        return $role;
    }

    // ---------------------------------------------------------------- migration and seeding

    public function test_permissions_are_in_the_database_and_system_roles_keep_their_old_access(): void
    {
        $this->assertSame(count(PermissionRegistry::all()), Permission::count());
        $this->assertSame(AdminRole::values(), Role::where('is_system', true)->orderBy('id')->pluck('slug')->all());

        foreach (AdminRole::cases() as $role) {
            if ($role === AdminRole::SuperAdmin) {
                continue;
            }
            $admin = $this->admin($role->value);
            foreach (PermissionRegistry::MODULES as $module => [, $view]) {
                $this->assertSame($role->allows($module), $admin->hasPermission($view), "{$role->value} / {$module}");
            }
            // Import / export only where granted on purpose.
            $this->assertFalse($admin->hasPermission('customers.export'), "{$role->value} must not export customers");
        }

        $this->assertTrue($this->admin('warehouse_manager')->hasPermission('inventory.export'));
        $this->assertFalse($this->admin('warehouse_manager')->hasPermission('payments.export'));
        $this->assertTrue($this->admin('super_admin')->hasPermission('customers.export'));
    }

    public function test_sync_is_idempotent_and_never_regrants_what_an_admin_removed(): void
    {
        $manager = Role::where('slug', 'manager')->sole();
        $manager->permissions()->detach(Permission::where('slug', 'orders.cancel')->value('id'));

        app(RbacSynchronizer::class)->sync();
        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertFalse($manager->fresh()->permissions->contains('slug', 'orders.cancel'));
        $this->assertSame(count(PermissionRegistry::all()), Permission::count());
    }

    // ---------------------------------------------------------------- dynamic roles

    public function test_a_new_role_works_immediately_and_permission_changes_apply_on_the_next_request(): void
    {
        $super = $this->admin('super_admin');
        $this->actingAs($super, 'admin')->post(route('dashboard.roles.store'), [
            'name' => 'Senior Accountant', 'description' => 'Money', 'is_active' => 1, 'permissions' => ['payments.view', 'reports.view'],
        ])->assertRedirect();
        $role = Role::where('name', 'Senior Accountant')->sole();
        $this->assertSame('senior_accountant', $role->slug);

        $accountant = $this->admin('senior_accountant');
        $this->actingAs($accountant, 'admin')->get('/dashboard/payments')->assertOk();
        $this->actingAs($accountant, 'admin')->get('/dashboard/orders')->assertForbidden();

        // Matrix cell: grant orders.view.
        $this->actingAs($super, 'admin')->patchJson(route('dashboard.roles.permission', [$role, 'orders.view']), ['granted' => 1])
            ->assertOk()->assertJsonPath('permissions_count', 3);
        $this->actingAs($accountant->fresh(), 'admin')->get('/dashboard/orders')->assertOk();

        // Remove payments.view.
        $this->actingAs($super, 'admin')->put(route('dashboard.roles.update', $role), ['name' => 'Senior Accountant', 'is_active' => 1, 'permissions' => ['orders.view']])->assertRedirect();
        $this->actingAs($accountant->fresh(), 'admin')->get('/dashboard/payments')->assertForbidden();

        // Deactivated role: no permissions at all.
        $this->actingAs($super, 'admin')->post(route('dashboard.roles.toggle', $role));
        $this->actingAs($accountant->fresh(), 'admin')->get('/dashboard/orders')->assertForbidden();

        $this->assertSame(['role.create', 'role.permissions', 'role.update', 'role.deactivate'],
            AdminAuditLog::where('action', 'like', 'role.%')->orderBy('id')->pluck('action')->all());
        $update = AdminAuditLog::where('action', 'role.update')->sole();
        $this->assertSame([[], ['payments.view', 'reports.view']], [$update->changes['added'], $update->changes['removed']]);
    }

    public function test_duplicate_and_delete_roles_safely(): void
    {
        $super = $this->admin('super_admin');

        $this->actingAs($super, 'admin')->post(route('dashboard.roles.duplicate', Role::where('slug', 'accountant')->sole()))->assertRedirect();
        $copy = Role::where('name', 'Copy of Accountant')->sole();
        $this->assertFalse($copy->is_active);
        $this->assertEqualsCanonicalizing(Role::where('slug', 'accountant')->sole()->permissions->pluck('slug')->all(), $copy->permissions->pluck('slug')->all());

        $this->actingAs($super, 'admin')->delete(route('dashboard.roles.destroy', Role::where('slug', 'manager')->sole()))->assertSessionHas('error');
        $this->assertTrue(Role::where('slug', 'manager')->exists());

        $inUse = $this->role('In use', ['orders.view']);
        $this->admin('in_use');
        $this->actingAs($super, 'admin')->delete(route('dashboard.roles.destroy', $inUse))->assertSessionHas('error');
        $this->assertTrue(Role::whereKey($inUse->id)->exists());

        $this->actingAs($super, 'admin')->delete(route('dashboard.roles.destroy', $copy))->assertRedirect();
        $this->assertFalse(Role::whereKey($copy->id)->exists());
    }

    public function test_the_super_admin_role_cannot_lose_permissions_be_deactivated_or_deleted(): void
    {
        $super = $this->admin('super_admin');
        $role = Role::where('slug', 'super_admin')->sole();

        $this->actingAs($super, 'admin')->patchJson(route('dashboard.roles.permission', [$role, 'orders.view']), ['granted' => 0])->assertStatus(422);
        $this->actingAs($super, 'admin')->post(route('dashboard.roles.toggle', $role))->assertSessionHas('error');
        $this->actingAs($super, 'admin')->delete(route('dashboard.roles.destroy', $role))->assertSessionHas('error');
        $this->actingAs($super, 'admin')->put(route('dashboard.roles.update', $role), ['name' => 'Owner', 'is_active' => 0, 'permissions' => []])->assertRedirect();

        $role->refresh();
        $this->assertSame(['Owner', true], [$role->name, $role->is_active]);
        $this->assertTrue($super->fresh()->hasPermission('admins.create'));
    }

    // ---------------------------------------------------------------- escalation

    public function test_role_managers_cannot_grant_what_they_do_not_have_or_change_their_own_role(): void
    {
        $roleManager = $this->role('Role manager', ['roles.view', 'roles.manage', 'orders.view']);
        $actor = $this->admin('role_manager');
        $target = $this->role('Agents', ['orders.view']);

        $this->actingAs($actor, 'admin')->patchJson(route('dashboard.roles.permission', [$target, 'payments.view']), ['granted' => 1])->assertStatus(422);
        $this->actingAs($actor, 'admin')->post(route('dashboard.roles.store'), ['name' => 'Sneaky', 'permissions' => ['admins.create']])->assertSessionHas('error');
        $this->assertFalse(Role::where('name', 'Sneaky')->exists());

        // Their own role: never.
        $this->actingAs($actor, 'admin')->patchJson(route('dashboard.roles.permission', [$roleManager, 'orders.view']), ['granted' => 0])->assertStatus(422);
        // A role with more than they have: only a super admin.
        $this->actingAs($actor, 'admin')->put(route('dashboard.roles.update', Role::where('slug', 'accountant')->sole()), ['name' => 'Accountant', 'permissions' => []])->assertSessionHas('error');

        // What they hold, they can grant.
        $this->actingAs($actor, 'admin')->patchJson(route('dashboard.roles.permission', [$target, 'orders.view']), ['granted' => 0])->assertOk();
        $this->assertFalse($target->fresh()->permissions->contains('slug', 'orders.view'));
    }

    public function test_assigning_roles_needs_roles_assign_and_cannot_hand_out_more_than_the_actor_has(): void
    {
        $this->role('Admin desk', ['admins.view', 'admins.update', 'admins.create', 'orders.view']);
        $desk = $this->admin('admin_desk');
        $agent = $this->admin('orders_agent');

        // No roles.assign: the role field is ignored as a change and refused.
        $this->actingAs($desk, 'admin')->put(route('dashboard.admins.update', $agent), ['name' => 'Agent', 'role' => 'manager', 'is_active' => 1])->assertSessionHas('error');
        $this->assertSame('orders_agent', $agent->fresh()->role);

        Role::where('slug', 'admin_desk')->sole()->permissions()->attach(Permission::where('slug', 'roles.assign')->value('id'));
        $this->actingAs($desk->fresh(), 'admin')->put(route('dashboard.admins.update', $agent), ['name' => 'Agent', 'role' => 'manager', 'is_active' => 1])->assertSessionHas('error');
        $this->actingAs($desk->fresh(), 'admin')->post(route('dashboard.admins.store'), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'long-enough-pw', 'role' => 'super_admin'])->assertSessionHas('error');
        $this->assertFalse(Admin::where('email', 'x@example.com')->exists());

        // A role inside their permissions is fine.
        $this->role('Viewer', ['orders.view']);
        $this->actingAs($desk->fresh(), 'admin')->put(route('dashboard.admins.update', $agent), ['name' => 'Agent', 'role' => 'viewer', 'is_active' => 1])->assertSessionHas('success');
        $this->assertSame('viewer', $agent->fresh()->role);
        // assertEquals: MySQL returns JSON object keys in its own order.
        $this->assertEquals(['from' => 'orders_agent', 'to' => 'viewer'], AdminAuditLog::where('action', 'admin.role_change')->sole()->changes);

        // Nobody changes their own role.
        $this->actingAs($desk->fresh(), 'admin')->put(route('dashboard.admins.update', $desk), ['name' => 'Desk', 'role' => 'viewer', 'is_active' => 1])->assertSessionHas('error');
    }

    public function test_non_super_admins_cannot_touch_super_admin_accounts(): void
    {
        $super = $this->admin('super_admin');
        $this->role('Admin desk', ['admins.view', 'admins.update', 'roles.assign']);
        $desk = $this->admin('admin_desk');

        $this->actingAs($desk, 'admin')->put(route('dashboard.admins.update', $super), ['name' => 'Hacked', 'is_active' => 0])->assertSessionHas('error');
        $this->assertSame([true, 'Super_admin'], [$super->fresh()->is_active, $super->fresh()->name]);
    }

    // ---------------------------------------------------------------- last super admin

    public function test_the_last_active_super_admin_cannot_be_demoted_or_deactivated(): void
    {
        $super = $this->admin('super_admin');
        $second = $this->admin('super_admin');

        $this->actingAs($super, 'admin')->put(route('dashboard.admins.update', $super), ['name' => 'Me', 'is_active' => 0])->assertSessionHas('error');
        $this->actingAs($super, 'admin')->put(route('dashboard.admins.update', $second), ['name' => 'Two', 'role' => 'manager', 'is_active' => 1])->assertSessionHas('success');

        // $super is now the only one left.
        $this->actingAs($super, 'admin')->put(route('dashboard.admins.update', $super), ['name' => 'Me', 'role' => 'manager', 'is_active' => 1])->assertSessionHas('error');
        $this->actingAs($super, 'admin')->put(route('dashboard.admins.update', $second), ['name' => 'Two', 'role' => 'super_admin', 'is_active' => 1])->assertSessionHas('success');
        $this->actingAs($second->fresh(), 'admin')->put(route('dashboard.admins.update', $super), ['name' => 'Me', 'is_active' => 0])->assertSessionHas('success');
        $this->actingAs($second->fresh(), 'admin')->put(route('dashboard.admins.update', $second), ['name' => 'Two', 'role' => 'manager', 'is_active' => 1])->assertSessionHas('error');

        $this->assertSame(1, Admin::where('role', 'super_admin')->where('is_active', true)->count());
        $this->assertTrue(AdminAuditLog::where('action', 'admin.deactivate')->exists());
    }

    // ---------------------------------------------------------------- enforcement

    public static function denied(): array
    {
        return [
            // [permissions the role has, method, url, data]
            'adjust points without loyalty.adjust_points' => [['loyalty.view'], 'post', '/dashboard/loyalty/customers/{customer}/adjust', ['points' => 10, 'reason' => 'x']],
            'create admin without admins.create' => [['admins.view'], 'post', '/dashboard/admins', ['name' => 'N', 'email' => 'n@example.com', 'password' => 'long-enough-pw', 'role' => 'manager']],
            'payments without payments.view' => [['orders.view'], 'get', '/dashboard/payments', []],
            'manage rewards without loyalty.manage_rewards' => [['loyalty.view'], 'post', '/dashboard/loyalty/rewards', ['code' => 'X', 'type' => 'voucher', 'title' => 'X', 'points_required' => 1]],
            'loyalty settings without loyalty.settings' => [['loyalty.view'], 'put', '/dashboard/loyalty/settings', []],
            'cancel an order without orders.cancel' => [['orders.view', 'orders.update_status'], 'put', '/dashboard/orders/{order}/update-status', ['status' => 'canceled']],
            'delete a product without products.delete' => [['products.view', 'products.update'], 'delete', '/dashboard/products/{product}', []],
            'roles without roles.manage' => [['roles.view'], 'post', '/dashboard/roles', ['name' => 'R']],
            'audit log without audit_log.view' => [['admins.view'], 'get', '/dashboard/audit-log', []],
            'export customers without customers.export' => [['products.export'], 'post', '/dashboard/data/exports', ['entity' => 'customers', 'format' => 'csv']],
            'import products without products.import' => [['inventory.import'], 'get', '/dashboard/data/templates/products', []],
        ];
    }

    #[DataProvider('denied')]
    public function test_sensitive_actions_are_refused_server_side_without_their_permission(array $permissions, string $method, string $url, array $data): void
    {
        $this->role('Limited', $permissions);
        $admin = $this->admin('limited');
        $customer = User::factory()->create();
        $order = \App\Models\Order::create(['user_id' => $customer->id, 'payment_method' => 'cash', 'delivery_fee' => 0, 'total_price' => 10, 'points_earned' => 0, 'status' => 'pending']);
        $product = \App\Models\Product::create(['name_ar' => 'م', 'name_en' => 'P', 'image' => 'products/p.png']);
        $url = strtr($url, ['{customer}' => $customer->id, '{order}' => $order->id, '{product}' => $product->id]);

        $this->actingAs($admin, 'admin')->{$method}($url, $data)->assertForbidden();

        $this->assertSame(0, \App\Models\LoyaltyLedgerEntry::count());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertFalse(Admin::where('email', 'n@example.com')->exists());
        $this->assertNotSoftDeleted($product);
    }

    public function test_menu_follows_permissions_and_admin_screens_render(): void
    {
        $super = $this->admin('super_admin');
        foreach (['/dashboard/roles', '/dashboard/roles/create', '/dashboard/roles/'.Role::where('slug', 'manager')->value('id'),
            '/dashboard/roles/'.Role::where('slug', 'manager')->value('id').'/edit', '/dashboard/admins', '/dashboard/admins/'.$super->id] as $url) {
            $this->actingAs($super, 'admin')->get($url)->assertOk();
        }
        $this->actingAs($super, 'admin')->get('/dashboard/admins/'.$super->id)->assertSee('roles.manage');

        $this->role('Stock only', ['inventory.view']);
        $this->actingAs($this->admin('stock_only'), 'admin')->get('/dashboard/inventory')->assertOk()
            ->assertSee('Stock overview')->assertDontSee('Roles &amp; permissions', false)->assertDontSee('>Orders<', false);
    }
}
