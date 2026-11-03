@extends('admin.layout')

@section('title', 'Roles & permissions')
@section('pretitle', 'Security')
@section('subtitle', 'A role is a set of permissions and every admin has one. Changes apply on the admin\'s next request.')

@section('actions')
    @can('admins.view')<a href="{{ route('dashboard.admins.index') }}" class="btn btn-outline-secondary"><i class="ti ti-users me-1"></i>Admins</a>@endcan
    @can('roles.manage')<a href="{{ route('dashboard.roles.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New role</a>@endcan
@endsection

@push('styles')
<style>
    .role-card .progress { height: .35rem; }
    .role-card .role-icon { width: 2.5rem; height: 2.5rem; display: inline-flex; align-items: center; justify-content: center; border-radius: .5rem; font-size: 1.25rem; }
    .role-card .role-description { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 2.6em; }
    .matrix-table th:first-child, .matrix-table td:first-child { position: sticky; left: 0; z-index: 1; background: var(--tblr-bg-surface); min-width: 16rem; box-shadow: 1px 0 0 var(--tblr-border-color); }
    .matrix-table thead th { white-space: nowrap; vertical-align: bottom; }
    .matrix-table td.matrix-check, .matrix-table th.matrix-check { text-align: center; width: 6.5rem; }
    .matrix-table tr.module-all td { background: var(--tblr-bg-surface-secondary); }
    .matrix-module .accordion-button { gap: .75rem; }
    .matrix-coverage { font-variant-numeric: tabular-nums; }
</style>
@endpush

@section('content')
@php
    $active = $roles->where('is_active', true)->count();
    $custom = $roles->where('is_system', false)->count();
    $countFor = fn ($role) => $role->is_super_admin ? $totalPermissions : $role->permissions_count;
@endphp

<div class="row row-cards mb-3">
    <div class="col-6 col-lg-3"><x-admin.stat label="Roles" icon="shield" :value="$roles->count()" :hint="$active.' active'" /></div>
    <div class="col-6 col-lg-3"><x-admin.stat label="Custom roles" icon="shield-plus" tone="purple" :value="$custom" hint="Created by admins" /></div>
    <div class="col-6 col-lg-3"><x-admin.stat label="Admins" icon="users" tone="blue" :value="$roles->sum('admins_count')" /></div>
    <div class="col-6 col-lg-3"><x-admin.stat label="Permissions" icon="key" tone="yellow" :value="$totalPermissions" :hint="$permissions->count().' modules'" /></div>
</div>

<h3 class="mb-2">Roles</h3>
<div class="row row-cards mb-4">
    @foreach ($roles as $role)
        @php
            $count = $countFor($role);
            $percent = $totalPermissions ? round($count * 100 / $totalPermissions) : 0;
            $tone = $role->is_super_admin ? 'red' : ($role->is_system ? 'blue' : 'purple');
        @endphp
        <div class="col-md-6 col-xl-4">
            <div class="card role-card h-100 {{ $role->is_active ? '' : 'opacity-75' }}">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3 mb-2">
                        <span class="role-icon bg-{{ $tone }}-lt text-{{ $tone }}"><i class="ti ti-{{ $role->is_super_admin ? 'crown' : ($role->is_system ? 'shield-lock' : 'shield') }}"></i></span>
                        <div class="min-w-0 flex-fill">
                            <a href="{{ route('dashboard.roles.show', $role) }}" class="fw-bold text-reset d-block text-truncate">{{ $role->name }}</a>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @if ($role->is_super_admin)<span class="badge bg-red-lt">Full access</span>@elseif ($role->is_system)<span class="badge bg-blue-lt">System</span>@else<span class="badge bg-purple-lt">Custom</span>@endif
                                <x-admin.status :status="$role->is_active ? 'active' : 'inactive'" />
                            </div>
                        </div>
                    </div>
                    <p class="text-secondary small role-description mb-3">{{ $role->description ?: 'No description.' }}</p>
                    <div class="d-flex justify-content-between small mb-1">
                        <span><i class="ti ti-users me-1 text-secondary"></i>{{ $role->admins_count }} {{ \Illuminate\Support\Str::plural('admin', $role->admins_count) }}</span>
                        <span class="matrix-coverage" data-role-count="{{ $role->id }}">{{ $count }} / {{ $totalPermissions }} permissions</span>
                    </div>
                    <div class="progress"><div class="progress-bar bg-{{ $tone }}" style="width: {{ $percent }}%" data-role-bar="{{ $role->id }}" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100" aria-label="Permissions granted"></div></div>
                </div>
                <div class="card-footer d-flex gap-2 py-2">
                    <a href="{{ route('dashboard.roles.show', $role) }}" class="btn btn-sm btn-ghost-secondary"><i class="ti ti-eye me-1"></i>View</a>
                    @can('roles.manage')
                        @if ($editable[$role->id] || ($role->is_super_admin && auth('admin')->user()->isSuperAdmin()))
                            <a href="{{ route('dashboard.roles.edit', $role) }}" class="btn btn-sm btn-ghost-primary"><i class="ti ti-edit me-1"></i>Edit</a>
                        @endif
                        <div class="dropdown ms-auto">
                            <button class="btn btn-sm btn-ghost-secondary btn-icon" data-bs-toggle="dropdown" aria-label="More actions for {{ $role->name }}"><i class="ti ti-dots-vertical"></i></button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <form action="{{ route('dashboard.roles.duplicate', $role) }}" method="POST">@csrf
                                    <button class="dropdown-item"><i class="ti ti-copy me-2"></i>Duplicate</button>
                                </form>
                                @if ($editable[$role->id])
                                    <form action="{{ route('dashboard.roles.toggle', $role) }}" method="POST" data-confirm="{{ $role->is_active ? 'Deactivate '.$role->name.'? Its admins lose its permissions until it is activated again.' : 'Activate '.$role->name.'?' }}">@csrf
                                        <button class="dropdown-item"><i class="ti ti-{{ $role->is_active ? 'player-pause' : 'player-play' }} me-2"></i>{{ $role->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                    @unless ($role->is_system)
                                        <div class="dropdown-divider"></div>
                                        <form action="{{ route('dashboard.roles.destroy', $role) }}" method="POST" data-confirm="Delete the role {{ $role->name }}?">@csrf @method('DELETE')
                                            <button class="dropdown-item text-danger" @disabled($role->admins_count > 0) title="{{ $role->admins_count ? 'Move its admins to another role first' : '' }}"><i class="ti ti-trash me-2"></i>Delete</button>
                                        </form>
                                    @endunless
                                @endif
                            </div>
                        </div>
                    @endcan
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="card">
    <div class="card-header flex-wrap gap-2">
        <div>
            <h3 class="card-title">Permission matrix</h3>
            <div class="small text-secondary">Ticking a box saves it immediately. Grey ticks are read-only (the super admin role, or roles you cannot change).</div>
        </div>
        <div class="card-actions d-flex flex-wrap gap-2 ms-auto">
            <div class="input-icon">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input type="search" class="form-control form-control-sm" id="matrix-search" placeholder="Find a permission…" aria-label="Find a permission">
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-matrix-expand="1"><i class="ti ti-arrows-maximize me-1"></i>Expand all</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-matrix-expand="0"><i class="ti ti-arrows-minimize me-1"></i>Collapse all</button>
        </div>
    </div>

    <div class="accordion accordion-flush" id="permission-matrix">
        @foreach ($permissions as $module => $items)
            @php $moduleId = 'module-'.$module; @endphp
            <div class="accordion-item matrix-module" data-module="{{ $module }}">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $moduleId }}" aria-expanded="false" aria-controls="{{ $moduleId }}">
                        <span class="fw-bold">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</span>
                        <span class="badge bg-secondary-lt">{{ $items->count() }}</span>
                        <span class="d-none d-md-flex flex-wrap gap-1 ms-auto me-3">
                            @foreach ($roles as $role)
                                @php $granted = $role->is_super_admin ? $items->count() : $items->filter(fn ($p) => $role->permissions->contains('slug', $p->slug))->count(); @endphp
                                <span class="badge {{ $granted === $items->count() ? 'bg-green-lt' : ($granted ? 'bg-yellow-lt' : 'bg-secondary-lt') }} matrix-coverage"
                                      title="{{ $role->name }}: {{ $granted }} of {{ $items->count() }}" data-module-badge="{{ $module }}-{{ $role->id }}">{{ \Illuminate\Support\Str::limit($role->name, 12) }} {{ $granted }}/{{ $items->count() }}</span>
                            @endforeach
                        </span>
                    </button>
                </h2>
                <div id="{{ $moduleId }}" class="accordion-collapse collapse" data-bs-parent="">
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter card-table matrix-table mb-0">
                            <thead>
                                <tr>
                                    <th>Permission</th>
                                    @foreach ($roles as $role)<th class="matrix-check small" title="{{ $role->name }}">{{ \Illuminate\Support\Str::limit($role->name, 14) }}</th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="module-all">
                                    <td class="small text-secondary">All in {{ strtolower(\App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module)) }}</td>
                                    @foreach ($roles as $role)
                                        <td class="matrix-check">
                                            @if ($editable[$role->id])
                                                <input type="checkbox" class="form-check-input matrix-column" data-module="{{ $module }}" data-role="{{ $role->id }}" aria-label="{{ $role->name }}: all {{ $module }} permissions">
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                                @foreach ($items as $permission)
                                    <tr class="matrix-row" data-search="{{ strtolower($permission->slug.' '.$permission->name.' '.$permission->description) }}">
                                        <td>
                                            <div class="fw-medium">{{ $permission->name }}</div>
                                            <div class="small text-secondary">{{ $permission->description }} <code class="ms-1">{{ $permission->slug }}</code></div>
                                        </td>
                                        @foreach ($roles as $role)
                                            @php $has = $role->is_super_admin || $role->permissions->contains('slug', $permission->slug); @endphp
                                            <td class="matrix-check">
                                                @if ($editable[$role->id])
                                                    <input type="checkbox" class="form-check-input matrix-cell" @checked($has)
                                                           data-url="{{ route('dashboard.roles.permission', [$role, $permission->slug]) }}" data-role="{{ $role->id }}" data-module="{{ $module }}"
                                                           aria-label="{{ $role->name }}: {{ $permission->name }}">
                                                @elseif ($has)
                                                    <i class="ti ti-check text-secondary" title="Granted (read-only)"></i>
                                                @else
                                                    <span class="text-secondary opacity-50">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    // Tabler bundles Bootstrap and exposes its components on window.tabler.
    const Collapse = (window.bootstrap || window.tabler || {}).Collapse;
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const total = {{ $totalPermissions }};

    async function save(box) {
        const response = await fetch(box.dataset.url, {
            method: 'PATCH',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token},
            body: JSON.stringify({granted: box.checked ? 1 : 0}),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'Could not save.');
        if (data.permissions_count !== undefined) {
            const count = document.querySelector('[data-role-count="' + box.dataset.role + '"]');
            const bar = document.querySelector('[data-role-bar="' + box.dataset.role + '"]');
            if (count) count.textContent = data.permissions_count + ' / ' + total + ' permissions';
            if (bar) bar.style.width = Math.round(data.permissions_count * 100 / total) + '%';
        }
    }

    // The "all in module" box of a role column reflects its cells: ticked, empty or partly (indeterminate).
    function syncColumn(module, role) {
        const cells = document.querySelectorAll('.matrix-cell[data-module="' + module + '"][data-role="' + role + '"]');
        const on = Array.from(cells).filter(c => c.checked).length;
        const column = document.querySelector('.matrix-column[data-module="' + module + '"][data-role="' + role + '"]');
        if (column) { column.checked = on === cells.length && on > 0; column.indeterminate = on > 0 && on < cells.length; }
        const badge = document.querySelector('[data-module-badge="' + module + '-' + role + '"]');
        if (badge && cells.length) {
            badge.textContent = badge.textContent.replace(/\d+\/\d+$/, on + '/' + cells.length);
            badge.className = 'badge matrix-coverage ' + (on === cells.length ? 'bg-green-lt' : (on ? 'bg-yellow-lt' : 'bg-secondary-lt'));
        }
    }

    document.querySelectorAll('.matrix-cell').forEach(function (box) {
        box.addEventListener('change', async function () {
            box.disabled = true;
            try { await save(box); } catch (e) { box.checked = !box.checked; alert(e.message); }
            finally { box.disabled = false; syncColumn(box.dataset.module, box.dataset.role); }
        });
    });

    document.querySelectorAll('.matrix-column').forEach(function (column) {
        syncColumn(column.dataset.module, column.dataset.role);
        column.addEventListener('change', async function () {
            const wanted = column.checked;
            column.disabled = true;
            const cells = Array.from(document.querySelectorAll('.matrix-cell[data-module="' + column.dataset.module + '"][data-role="' + column.dataset.role + '"]'))
                .filter(c => c.checked !== wanted);
            for (const cell of cells) {
                cell.checked = wanted; cell.disabled = true;
                try { await save(cell); } catch (e) { cell.checked = !wanted; alert(e.message); cell.disabled = false; break; }
                cell.disabled = false;
            }
            column.disabled = false;
            syncColumn(column.dataset.module, column.dataset.role);
        });
    });

    const setOpen = (open) => document.querySelectorAll('#permission-matrix .accordion-collapse').forEach(el => Collapse.getOrCreateInstance(el, {toggle: false})[open ? 'show' : 'hide']());
    document.querySelectorAll('[data-matrix-expand]').forEach(b => b.addEventListener('click', () => setOpen(b.dataset.matrixExpand === '1')));

    const search = document.getElementById('matrix-search');
    search && search.addEventListener('input', function () {
        const q = search.value.trim().toLowerCase();
        document.querySelectorAll('.matrix-module').forEach(function (module) {
            let hits = 0;
            module.querySelectorAll('.matrix-row').forEach(row => { const hit = q === '' || row.dataset.search.includes(q); row.classList.toggle('d-none', !hit); hits += hit ? 1 : 0; });
            module.classList.toggle('d-none', hits === 0);
            if (q !== '' && hits) Collapse.getOrCreateInstance(module.querySelector('.accordion-collapse'), {toggle: false}).show();
        });
    });
})();
</script>
@endpush
