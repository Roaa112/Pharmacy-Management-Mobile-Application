<?php

namespace App\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Permission;
use App\Modules\Admin\Services\AdminAccountService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Dashboard accounts and their roles. The rules live in AdminAccountService. */
class AdminUserController extends Controller
{
    public function __construct(private AdminAccountService $accounts)
    {
    }

    public function index()
    {
        $actor = auth('admin')->user();

        return view('dashboard.admins.index', [
            'admins' => Admin::with('roleModel')->orderBy('name')->get(),
            'roles' => $this->accounts->assignableRoles($actor),
            'canAssign' => $actor->can('roles.assign'),
        ]);
    }

    /** One account and what it can actually do (its role's active permissions, by module). */
    public function show(Admin $admin)
    {
        $admin->load('roleModel');
        $granted = $admin->permissionSlugs();

        return view('dashboard.admins.show', [
            'admin' => $admin,
            'permissions' => Permission::whereIn('slug', $granted)->orderBy('module')->orderBy('slug')->get()->groupBy('module'),
            'roles' => $this->accounts->assignableRoles(auth('admin')->user()),
            'canAssign' => auth('admin')->user()->can('roles.assign'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:admins,email',
            'phone' => 'nullable|string|max:30',
            'password' => ['required', Password::min(10)],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')],
        ]);

        try {
            $this->accounts->create(auth('admin')->user(), $data);
        } catch (DomainException $e) {
            return redirect()->back()->withInput($request->except('password'))->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Admin account created.');
    }

    public function update(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'role' => ['nullable', 'string', Rule::exists('roles', 'slug')],
            'is_active' => 'nullable|boolean',
            'password' => ['nullable', Password::min(10)],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        try {
            $this->accounts->update(auth('admin')->user(), $admin, $data);
        } catch (DomainException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Admin account saved.');
    }
}
