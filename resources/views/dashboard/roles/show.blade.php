@extends('admin.layout')

@section('title', $role->name)
@section('pretitle', 'Roles & permissions')
@section('subtitle', $role->description)

@section('actions')
    <a href="{{ route('dashboard.roles.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Roles</a>
    @can('roles.manage')<a href="{{ route('dashboard.roles.edit', $role) }}" class="btn btn-primary"><i class="ti ti-edit me-1"></i>Edit</a>@endcan
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-4"><x-admin.stat label="Status" icon="shield" :value="$role->is_active ? 'Active' : 'Inactive'" :hint="$role->is_super_admin ? 'Full access' : ($role->is_system ? 'System role' : 'Custom role')" /></div>
    <div class="col-sm-4"><x-admin.stat label="Admins" icon="users" tone="blue" :value="$admins->count()" /></div>
    <div class="col-sm-4"><x-admin.stat label="Permissions" icon="key" tone="yellow" :value="$permissions->flatten()->count()" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Admins with this role</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($admins as $admin)
                    <a href="{{ route('dashboard.admins.show', $admin) }}" class="list-group-item list-group-item-action d-flex">
                        <span>{{ $admin->name }}<span class="d-block small text-secondary">{{ $admin->email }}</span></span>
                        <x-admin.status class="ms-auto align-self-center" :status="$admin->is_active === false ? 'inactive' : 'active'" />
                    </a>
                @empty
                    <div class="list-group-item text-secondary">Nobody has this role.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Permissions</h3></div>
            <div class="card-body">
                @forelse ($permissions as $module => $items)
                    <div class="mb-3">
                        <div class="fw-medium">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</div>
                        @foreach ($items as $permission)<span class="badge bg-green-lt me-1 mb-1" title="{{ $permission->description }}">{{ $permission->slug }}</span>@endforeach
                    </div>
                @empty
                    <p class="text-secondary mb-0">This role grants nothing yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
