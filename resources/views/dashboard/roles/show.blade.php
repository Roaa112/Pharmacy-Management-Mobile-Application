@extends('admin.layout')

@section('title', $role->name)
@section('pretitle')
    <a href="{{ route('dashboard.roles.index') }}" class="text-reset">Roles & permissions</a>
@endsection
@section('subtitle', $role->description)

@section('actions')
    <a href="{{ route('dashboard.roles.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Roles</a>
    @can('roles.manage')<a href="{{ route('dashboard.roles.edit', $role) }}" class="btn btn-primary"><i class="ti ti-edit me-1"></i>Edit role</a>@endcan
@endsection

@section('content')
@php $tone = $role->is_super_admin ? 'red' : ($role->is_system ? 'blue' : 'purple'); @endphp
<div class="row row-cards mb-3">
    <div class="col-sm-4"><x-admin.stat label="Type" :icon="$role->is_super_admin ? 'crown' : 'shield'" :tone="$tone" :value="$role->is_super_admin ? 'Full access' : ($role->is_system ? 'System role' : 'Custom role')" :hint="$role->is_active ? 'Active' : 'Inactive: grants nothing'" /></div>
    <div class="col-sm-4"><x-admin.stat label="Admins" icon="users" tone="blue" :value="$admins->count()" /></div>
    <div class="col-sm-4"><x-admin.stat label="Permissions" icon="key" tone="yellow" :value="$permissions->flatten()->count()" :hint="$permissions->count().' modules'" /></div>
</div>

<div class="row row-cards">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Admins with this role</h3></div>
            <div class="list-group list-group-flush">
                @forelse ($admins as $admin)
                    <a href="{{ route('dashboard.admins.show', $admin) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                        <span class="avatar avatar-sm bg-{{ $tone }}-lt text-{{ $tone }}">{{ mb_strtoupper(mb_substr($admin->name, 0, 1)) }}</span>
                        <span class="min-w-0">{{ $admin->name }}<span class="d-block small text-secondary text-truncate">{{ $admin->email }}</span></span>
                        <x-admin.status class="ms-auto" :status="$admin->is_active === false ? 'inactive' : 'active'" />
                    </a>
                @empty
                    <div class="list-group-item text-secondary">Nobody has this role yet.</div>
                @endforelse
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        @if ($permissions->isEmpty())
            <div class="card"><x-admin.empty icon="key-off" title="No permissions" text="This role grants nothing yet." /></div>
        @else
            <div class="row row-cards">
                @foreach ($permissions as $module => $items)
                    <div class="col-md-6">
                        <div class="card h-100">
                            <div class="card-header py-2">
                                <h3 class="card-title fs-4">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</h3>
                                <span class="badge bg-green-lt ms-auto">{{ $items->count() }}</span>
                            </div>
                            <ul class="list-unstyled card-body py-2 mb-0">
                                @foreach ($items as $permission)
                                    <li class="d-flex gap-2 py-1" title="{{ $permission->description }}">
                                        <i class="ti ti-circle-check text-green mt-1"></i>
                                        <span>{{ $permission->name }}<code class="d-block small">{{ $permission->slug }}</code></span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
