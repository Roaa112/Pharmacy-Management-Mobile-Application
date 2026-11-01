<?php

namespace App\Modules\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Dashboard accounts and their roles ("admins" permission). */
class AdminUserController extends Controller
{
    public function index()
    {
        return view('dashboard.admins.index', [
            'admins' => Admin::orderBy('name')->get(),
            'roles' => AdminRole::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:admins,email',
            'phone' => 'nullable|string|max:30',
            'password' => ['required', Password::min(10)],
            'role' => ['required', Rule::in(AdminRole::values())],
        ]);

        $admin = Admin::create($data);
        $admin->forceFill(['role' => $data['role'], 'is_active' => true])->save();
        AdminAuditLog::record('admin.create', $admin, ['email' => $admin->email, 'role' => $data['role']]);

        return redirect()->back()->with('success', 'Admin account created.');
    }

    public function update(Request $request, Admin $admin)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:30',
            'role' => ['required', Rule::in(AdminRole::values())],
            'is_active' => 'nullable|boolean',
            'password' => ['nullable', Password::min(10)],
        ]);

        $isActive = $request->boolean('is_active');
        $removesLastSuperAdmin = $admin->roleEnum() === AdminRole::SuperAdmin
            && ($data['role'] !== AdminRole::SuperAdmin->value || !$isActive)
            && Admin::where('role', AdminRole::SuperAdmin->value)->where('is_active', true)->whereKeyNot($admin->id)->doesntExist();

        if ($removesLastSuperAdmin) {
            return redirect()->back()->with('error', 'There must always be at least one active super admin.');
        }

        $before = $admin->only(['name', 'role', 'is_active']);
        $admin->fill(['name' => $data['name'], 'phone' => $data['phone'] ?? $admin->phone]);
        if (!empty($data['password'])) {
            $admin->password = $data['password'];
        }
        $admin->forceFill(['role' => $data['role'], 'is_active' => $isActive])->save();

        AdminAuditLog::record('admin.update', $admin, [
            'before' => $before,
            'after' => $admin->only(['name', 'role', 'is_active']),
            'password_changed' => !empty($data['password']),
        ]);

        return redirect()->back()->with('success', 'Admin account saved.');
    }
}
