@extends('admin.layout')

@section('title', 'Roles & permissions')
@section('pretitle', 'Security')
@section('subtitle', 'A role is a set of permissions; every admin has one. Changes apply on the admin\'s next request.')

@section('actions')
    @can('admins.view')<a href="{{ route('dashboard.admins.index') }}" class="btn btn-outline-secondary"><i class="ti ti-users me-1"></i>Admins</a>@endcan
    @can('roles.manage')<a href="{{ route('dashboard.roles.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New role</a>@endcan
@endsection

@section('content')
<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead><tr><th>Role</th><th class="text-end">Admins</th><th class="text-end">Permissions</th><th>Status</th><th class="w-1"></th></tr></thead>
            <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td>
                        <a href="{{ route('dashboard.roles.show', $role) }}" class="fw-medium">{{ $role->name }}</a>
                        @if ($role->is_super_admin)<span class="badge bg-red-lt ms-1">Full access</span>@elseif ($role->is_system)<span class="badge bg-blue-lt ms-1">System</span>@endif
                        @if ($role->description)<div class="small text-secondary">{{ $role->description }}</div>@endif
                    </td>
                    <td class="text-end">{{ $role->admins_count }}</td>
                    <td class="text-end" data-role-count="{{ $role->id }}">{{ $role->is_super_admin ? $totalPermissions : $role->permissions_count }}</td>
                    <td><x-admin.status :status="$role->is_active ? 'active' : 'inactive'" /></td>
                    <td class="text-nowrap">
                        @can('roles.manage')
                            @if ($editable[$role->id])
                                <a href="{{ route('dashboard.roles.edit', $role) }}" class="btn btn-sm btn-ghost-primary btn-icon" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></a>
                            @endif
                            <form action="{{ route('dashboard.roles.duplicate', $role) }}" method="POST" class="d-inline">@csrf
                                <button class="btn btn-sm btn-ghost-secondary btn-icon" title="Duplicate" aria-label="Duplicate"><i class="ti ti-copy"></i></button>
                            </form>
                            @if ($editable[$role->id])
                                <form action="{{ route('dashboard.roles.toggle', $role) }}" method="POST" class="d-inline" data-confirm="{{ $role->is_active ? 'Deactivate '.$role->name.'? Its admins lose its permissions until it is activated again.' : 'Activate '.$role->name.'?' }}">@csrf
                                    <button class="btn btn-sm btn-ghost-{{ $role->is_active ? 'warning' : 'success' }} btn-icon" title="{{ $role->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $role->is_active ? 'Deactivate' : 'Activate' }}"><i class="ti ti-{{ $role->is_active ? 'player-pause' : 'player-play' }}"></i></button>
                                </form>
                                @if (!$role->is_system)
                                    <x-admin.delete :action="route('dashboard.roles.destroy', $role)" :confirm="'Delete the role '.$role->name.'?'" />
                                @endif
                            @endif
                        @endcan
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Permission matrix</h3>
        <div class="card-actions">
            <input type="search" class="form-control form-control-sm" id="matrix-search" placeholder="Filter permissions…" aria-label="Filter permissions">
        </div>
    </div>
    <div class="table-responsive" style="max-height: 70vh">
        <table class="table table-sm card-table table-hover" id="permission-matrix">
            <thead class="sticky-top bg-body">
            <tr>
                <th>Permission</th>
                @foreach ($roles as $role)<th class="text-center small text-nowrap">{{ $role->name }}</th>@endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($permissions as $module => $items)
                <tr class="table-light matrix-group"><td colspan="{{ $roles->count() + 1 }}" class="fw-bold small text-uppercase">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</td></tr>
                @foreach ($items as $permission)
                    <tr class="matrix-row" data-search="{{ strtolower($permission->slug.' '.$permission->name.' '.$module) }}">
                        <td><span title="{{ $permission->description }}">{{ $permission->name }}</span> <code class="small text-secondary">{{ $permission->slug }}</code></td>
                        @foreach ($roles as $role)
                            @php $has = $role->is_super_admin || $role->permissions->contains('slug', $permission->slug); @endphp
                            <td class="text-center">
                                @if ($editable[$role->id])
                                    <input type="checkbox" class="form-check-input matrix-cell" @checked($has)
                                           data-url="{{ route('dashboard.roles.permission', [$role, $permission->slug]) }}" data-role="{{ $role->id }}"
                                           aria-label="{{ $role->name }}: {{ $permission->name }}">
                                @elseif ($has)
                                    <i class="ti ti-check text-green" aria-label="granted"></i>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary">Ticking a box saves it immediately. The super admin role always has every permission. Roles you cannot change are read-only.</div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    document.querySelectorAll('.matrix-cell').forEach(function (box) {
        box.addEventListener('change', async function () {
            box.disabled = true;
            try {
                const response = await fetch(box.dataset.url, {
                    method: 'PATCH',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
                    body: JSON.stringify({granted: box.checked ? 1 : 0}),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) { box.checked = !box.checked; alert(data.message || 'Could not save.'); return; }
                const count = document.querySelector('[data-role-count="' + box.dataset.role + '"]');
                if (count && data.permissions_count !== undefined) count.textContent = data.permissions_count;
            } catch (e) {
                box.checked = !box.checked; alert('Could not save.');
            } finally { box.disabled = false; }
        });
    });
    const search = document.getElementById('matrix-search');
    search && search.addEventListener('input', function () {
        const q = search.value.trim().toLowerCase();
        document.querySelectorAll('.matrix-row').forEach(row => row.classList.toggle('d-none', q !== '' && !row.dataset.search.includes(q)));
    });
})();
</script>
@endpush
