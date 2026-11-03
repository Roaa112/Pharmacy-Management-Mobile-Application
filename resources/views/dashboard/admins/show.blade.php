@extends('admin.layout')

@section('title', $admin->name)
@section('pretitle', 'Admins & roles')
@section('subtitle', $admin->email)

@section('actions')
    <a href="{{ route('dashboard.admins.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>All admins</a>
    @if ($admin->roleModel)
        @can('roles.view')<a href="{{ route('dashboard.roles.show', $admin->roleModel) }}" class="btn btn-outline-primary"><i class="ti ti-shield-lock me-1"></i>Role: {{ $admin->roleName() }}</a>@endcan
    @endif
@endsection

@section('content')
<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Role" icon="shield" :value="$admin->roleName()" :hint="$admin->roleModel?->is_super_admin ? 'Every permission' : ($admin->roleModel?->is_active ? 'Active role' : 'Role inactive')" /></div>
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Account" icon="user" :value="$admin->is_active === false ? 'Inactive' : 'Active'" tone="green" /></div>
    <div class="col-sm-6 col-lg-3"><x-admin.stat label="Effective permissions" icon="key" tone="yellow" :value="$permissions->flatten()->count()" /></div>
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">What this account can do</h3></div>
    <div class="card-body">
        @if ($admin->is_active === false)
            <p class="text-secondary mb-0">The account is inactive: it cannot sign in and has no permissions.</p>
        @elseif ($permissions->isEmpty())
            <p class="text-secondary mb-0">No permissions. The role is inactive or grants nothing.</p>
        @else
            <div class="row g-3">
                @foreach ($permissions as $module => $items)
                    <div class="col-md-6 col-xl-4">
                        <div class="fw-medium mb-1">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</div>
                        <ul class="list-unstyled small mb-0">
                            @foreach ($items as $permission)
                                <li><i class="ti ti-check text-green me-1"></i>{{ $permission->name }} <code class="text-secondary">{{ $permission->slug }}</code></li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
