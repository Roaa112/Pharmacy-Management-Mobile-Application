@extends('admin.layout')

@section('title', 'Admins & roles')
@section('pretitle', 'Security')
@section('subtitle', 'Who can use the dashboard and which role each account has. What a role allows is edited under Roles.')

@section('actions')
    @can('roles.view')<a href="{{ route('dashboard.roles.index') }}" class="btn btn-outline-primary"><i class="ti ti-shield-lock me-1"></i>Roles & permissions</a>@endcan
    @can('audit_log.view')<a href="{{ route('dashboard.audit.index') }}" class="btn btn-outline-secondary"><i class="ti ti-list-search me-1"></i>Audit log</a>@endcan
    @can('admins.create')<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAdmin"><i class="ti ti-plus me-1"></i>New admin</button>@endcan
@endsection

@section('content')
<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th class="w-1"></th></tr></thead>
            <tbody>
            @foreach ($admins as $admin)
                <tr>
                    <td class="fw-medium"><a href="{{ route('dashboard.admins.show', $admin) }}">{{ $admin->name }}</a>
                        @if ($admin->is(auth('admin')->user()))<span class="badge bg-blue-lt ms-1">You</span>@endif</td>
                    <td>{{ $admin->email }}</td>
                    <td>
                        {{ $admin->roleName() }}
                        @if ($admin->roleModel && !$admin->roleModel->is_active)<span class="badge bg-secondary-lt ms-1" title="This role is inactive: the account has no permissions">role inactive</span>@endif
                    </td>
                    <td><x-admin.status :status="$admin->is_active === false ? 'inactive' : 'active'" /></td>
                    <td class="text-nowrap">
                        <a href="{{ route('dashboard.admins.show', $admin) }}" class="btn btn-sm btn-ghost-secondary btn-icon" title="Effective permissions" aria-label="Effective permissions"><i class="ti ti-eye"></i></a>
                        @can('admins.update')<button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editAdmin{{ $admin->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>@endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('modals')
    @php $creating = auth('admin')->user()->can('admins.create'); $updating = auth('admin')->user()->can('admins.update'); @endphp
    @foreach ([null, ...$admins] as $admin)
        @php $editing = $admin !== null; @endphp
        @continue(($editing && !$updating) || (!$editing && !$creating))
        <x-admin.modal :id="$editing ? 'editAdmin'.$admin->id : 'createAdmin'" :title="$editing ? 'Edit '.$admin->name : 'New admin'"
                       :action="$editing ? route('dashboard.admins.update', $admin) : route('dashboard.admins.store')" :method="$editing ? 'PUT' : 'POST'">
            <x-admin.input name="name" label="Name" :value="$admin?->name" required />
            @unless ($editing)<x-admin.input type="email" name="email" label="Email" required />@endunless
            <x-admin.input name="phone" label="Phone" :value="$admin?->phone" />
            @if (!$editing || $canAssign)
                <div class="mb-3">
                    <label class="form-label required">Role</label>
                    <select name="role" class="form-select">
                        @if ($editing && !$roles->contains('slug', $admin->role))
                            <option value="{{ $admin->role }}" selected>{{ $admin->roleName() }} (current)</option>
                        @endif
                        @foreach ($roles as $role)<option value="{{ $role->slug }}" @selected(($admin?->role ?? 'orders_agent') === $role->slug)>{{ $role->name }}</option>@endforeach
                    </select>
                    <small class="form-hint">Only active roles you are allowed to assign are listed.</small>
                </div>
            @endif
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
