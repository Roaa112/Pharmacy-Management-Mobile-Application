<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Admin\Services\RoleService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Roles and the permission matrix. Every rule is in RoleService. */
class RoleController extends Controller
{
    public function __construct(private RoleService $roles)
    {
    }

    public function index()
    {
        $roles = Role::withCount(['admins', 'permissions'])->with('permissions:id,slug')->orderByDesc('is_super_admin')->orderByDesc('is_system')->orderBy('name')->get();
        $actor = auth('admin')->user();

        return view('dashboard.roles.index', [
            'roles' => $roles,
            'permissions' => Permission::where('is_active', true)->orderBy('module')->orderBy('id')->get()->groupBy('module'),
            'editable' => $roles->mapWithKeys(fn (Role $role) => [$role->id => $actor->can('roles.manage') && $this->roles->canEdit($actor, $role)]),
            'totalPermissions' => Permission::where('is_active', true)->count(),
        ]);
    }

    public function show(Role $role)
    {
        return view('dashboard.roles.show', [
            'role' => $role->load('permissions'),
            'admins' => $role->admins()->orderBy('name')->get(),
            'permissions' => $role->is_super_admin
                ? Permission::where('is_active', true)->orderBy('module')->get()->groupBy('module')
                : $role->permissions->where('is_active', true)->sortBy('module')->groupBy('module'),
        ]);
    }

    public function create()
    {
        return $this->form(new Role(['is_active' => true]));
    }

    public function edit(Role $role)
    {
        if (!$this->roles->canEdit(auth('admin')->user(), $role) && !($role->is_super_admin && auth('admin')->user()->isSuperAdmin())) {
            return redirect()->route('dashboard.roles.index')->with('error', $role->is_super_admin
                ? 'The super admin role always has every permission and cannot be changed.'
                : 'You cannot change this role.');
        }

        return $this->form($role->load('permissions'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        try {
            $role = $this->roles->create(auth('admin')->user(), $data);
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.roles.show', $role)->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role)
    {
        $data = $this->validated($request, $role);

        try {
            $this->roles->update(auth('admin')->user(), $role, $data);
        } catch (DomainException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.roles.show', $role)->with('success', 'Role saved.');
    }

    public function duplicate(Role $role)
    {
        try {
            $copy = $this->roles->duplicate(auth('admin')->user(), $role);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.roles.edit', $copy)->with('success', 'Role duplicated. The copy is inactive until you activate it.');
    }

    public function toggle(Role $role)
    {
        try {
            $role = $this->roles->toggle(auth('admin')->user(), $role);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', $role->is_active ? 'Role activated.' : 'Role deactivated: its admins lost its permissions.');
    }

    public function destroy(Role $role)
    {
        try {
            $this->roles->delete(auth('admin')->user(), $role);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.roles.index')->with('success', 'Role deleted.');
    }

    /** Matrix cell: JSON for the page script, a redirect for plain form posts. */
    public function setPermission(Request $request, Role $role, Permission $permission)
    {
        $request->validate(['granted' => 'required|boolean']);

        try {
            $this->roles->setPermission(auth('admin')->user(), $role, $permission, $request->boolean('granted'));
        } catch (DomainException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : redirect()->back()->with('error', $e->getMessage());
        }

        $count = $role->permissions()->count();

        return $request->expectsJson()
            ? response()->json(['message' => 'Saved.', 'granted' => $request->boolean('granted'), 'permissions_count' => $count])
            : redirect()->back()->with('success', 'Permission saved.');
    }

    private function form(Role $role)
    {
        $actor = auth('admin')->user();

        return view('dashboard.roles.form', [
            'role' => $role,
            'permissions' => Permission::where('is_active', true)->orderBy('module')->orderBy('id')->get()->groupBy('module'),
            'selected' => old('permissions', $role->exists ? $role->permissions->pluck('slug')->all() : []),
            'grantable' => $actor->permissionSlugs(),
        ]);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->ignore($role?->id)],
            'description' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'permissions' => 'nullable|array|max:500',
            'permissions.*' => 'string|max:80',
        ]);
        $data['is_active'] = $request->boolean('is_active');
        $data['permissions'] = $data['permissions'] ?? [];

        return $data;
    }
}
