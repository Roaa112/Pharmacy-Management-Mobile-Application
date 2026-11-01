@extends('admin.layout')

@section('title', 'Admins & roles')
@section('pretitle', 'Security')
@section('subtitle', 'Who can use the dashboard and what their role allows. Health records are visible only to Super admin and Health specialist.')

@section('actions')
    <a href="{{ route('dashboard.audit.index') }}" class="btn btn-outline-secondary"><i class="ti ti-list-search me-1"></i>Audit log</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAdmin"><i class="ti ti-plus me-1"></i>New admin</button>
@endsection

@section('content')
<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="w-1"></th></tr></thead>
            <tbody>
            @foreach ($admins as $admin)
                <tr>
                    <td class="fw-medium">{{ $admin->name }}</td>
                    <td>{{ $admin->email }}</td>
                    <td>{{ $admin->roleEnum()->label() }}</td>
                    <td><x-admin.status :status="$admin->is_active === false ? 'inactive' : 'active'" /></td>
                    <td><button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editAdmin{{ $admin->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">What each role can do</h3></div>
    <div class="table-responsive">
        <table class="table table-sm card-table">
            <thead><tr><th>Permission</th>@foreach ($roles as $role)<th class="text-center">{{ $role->label() }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach (\App\Enums\Permission::cases() as $permission)
                <tr>
                    <td>{{ ucfirst(str_replace('_', ' ', $permission->value)) }}</td>
                    @foreach ($roles as $role)<td class="text-center">@if ($role->allows($permission->value))<i class="ti ti-check text-green"></i>@endif</td>@endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('modals')
    @foreach ([null, ...$admins] as $admin)
        @php $editing = $admin !== null; @endphp
        <x-admin.modal :id="$editing ? 'editAdmin'.$admin->id : 'createAdmin'" :title="$editing ? 'Edit '.$admin->name : 'New admin'"
                       :action="$editing ? route('dashboard.admins.update', $admin) : route('dashboard.admins.store')" :method="$editing ? 'PUT' : 'POST'">
            <x-admin.input name="name" label="Name" :value="$admin?->name" required />
            @unless ($editing)<x-admin.input type="email" name="email" label="Email" required />@endunless
            <x-admin.input name="phone" label="Phone" :value="$admin?->phone" />
            <div class="mb-3">
                <label class="form-label required">Role</label>
                <select name="role" class="form-select">
                    @foreach ($roles as $role)<option value="{{ $role->value }}" @selected(($admin?->roleEnum() ?? \App\Enums\AdminRole::OrdersAgent) === $role)>{{ $role->label() }}</option>@endforeach
                </select>
            </div>
            <x-admin.input type="password" name="password" label="Password" autocomplete="new-password" :required="!$editing" :hint="$editing ? 'Leave empty to keep the current password.' : 'At least 10 characters.'" />
            @if ($editing)
                <label class="form-check form-switch mb-0">
                    <input type="hidden" name="is_active" value="0">
                    <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($admin->is_active !== false)>
                    <span class="form-check-label">Active (can sign in)</span>
                </label>
            @endif
        </x-admin.modal>
    @endforeach
@endpush
