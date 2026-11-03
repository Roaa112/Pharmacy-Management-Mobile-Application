@extends('admin.layout')

@section('title', $role->exists ? 'Edit role' : 'New role')
@section('pretitle')
    <a href="{{ route('dashboard.roles.index') }}" class="text-reset">Roles & permissions</a>
@endsection
@section('subtitle', $role->exists ? $role->name : 'Name the role, then choose what it can do.')

@section('actions')
    <a href="{{ route('dashboard.roles.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Roles</a>
@endsection

@push('styles')
<style>
    @media (min-width: 992px) { .role-summary { position: sticky; top: 1rem; } }
    .permission-module .accordion-button { gap: .75rem; }
    .permission-item { border-radius: .375rem; padding: .5rem .75rem; }
    .permission-item:hover { background: var(--tblr-bg-surface-secondary); }
    .permission-item.is-locked { opacity: .6; }
    .module-summary li { display: flex; justify-content: space-between; padding: .15rem 0; }
</style>
@endpush

@section('content')
@php
    $super = $role->is_super_admin;
    $isSuperActor = auth('admin')->user()->isSuperAdmin();
    $total = $permissions->flatten()->count();
@endphp
<form method="POST" action="{{ $role->exists ? route('dashboard.roles.update', $role) : route('dashboard.roles.store') }}" id="role-form">
    @csrf
    @if ($role->exists) @method('PUT') @endif

    <div class="row row-cards">
        <div class="col-lg-4">
            <div class="role-summary">
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Role details</h3></div>
                    <div class="card-body">
                        <x-admin.input name="name" label="Role name" :value="old('name', $role->name)" required maxlength="80" placeholder="e.g. Senior accountant" />
                        <x-admin.input type="textarea" name="description" label="Description" :value="old('description', $role->description)" maxlength="255" rows="2" placeholder="What is this role for?" />
                        @unless ($super)
                            <label class="form-check form-switch mb-2">
                                <input type="hidden" name="is_active" value="0">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active ?? true))>
                                <span class="form-check-label">Active</span>
                            </label>
                            <small class="form-hint mb-0">An inactive role gives its admins no permissions.</small>
                        @endunless
                        @if ($role->exists)<div class="small text-secondary mt-3">Identifier <code>{{ $role->slug }}</code> · never changes</div>@endif
                    </div>
                </div>

                @unless ($super)
                    <div class="card mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-baseline mb-1">
                                <span class="fw-medium">Selected</span>
                                <span class="h3 mb-0"><span id="selected-total">0</span><span class="text-secondary fs-5"> / {{ $total }}</span></span>
                            </div>
                            <div class="progress progress-sm mb-3"><div class="progress-bar" id="selected-bar" style="width: 0%"></div></div>
                            <ul class="list-unstyled small module-summary mb-0">
                                @foreach ($permissions as $module => $items)
                                    <li data-summary="{{ $module }}"><span>{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</span><span class="text-secondary"><span data-summary-count>0</span>/{{ $items->count() }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endunless

                @unless ($isSuperActor)
                    <div class="alert alert-info small"><i class="ti ti-lock me-1"></i>You can only grant permissions you have yourself; the others are locked.</div>
                @endunless

                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-fill"><i class="ti ti-device-floppy me-1"></i>Save role</button>
                    <a href="{{ $role->exists ? route('dashboard.roles.show', $role) : route('dashboard.roles.index') }}" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            @if ($super)
                <div class="card">
                    <div class="card-body text-center py-5">
                        <span class="avatar avatar-lg bg-red-lt text-red mb-3"><i class="ti ti-crown fs-1"></i></span>
                        <h3>Full access</h3>
                        <p class="text-secondary mb-0">The super admin role always has every permission. Only its name and description can be changed.</p>
                    </div>
                </div>
            @else
                <div class="card mb-3">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
                        <div class="input-icon flex-fill" style="min-width: 14rem">
                            <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                            <input type="search" id="permission-search" class="form-control" placeholder="Find a permission…" aria-label="Find a permission">
                        </div>
                        <button type="button" class="btn btn-outline-primary" data-select="all"><i class="ti ti-checks me-1"></i>Select all</button>
                        <button type="button" class="btn btn-outline-secondary" data-select="none"><i class="ti ti-square-off me-1"></i>Clear all</button>
                        <button type="button" class="btn btn-ghost-secondary" data-expand="1">Expand all</button>
                        <button type="button" class="btn btn-ghost-secondary" data-expand="0">Collapse all</button>
                    </div>
                </div>

                <div class="card">
                    <div class="accordion accordion-flush" id="permission-modules">
                        @foreach ($permissions as $module => $items)
                            @php $moduleId = 'perm-'.$module; @endphp
                            <div class="accordion-item permission-module" data-module="{{ $module }}">
                                <h2 class="accordion-header d-flex align-items-center pe-3">
                                    <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }}" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $moduleId }}" aria-expanded="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $moduleId }}">
                                        <span class="fw-bold">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</span>
                                        <span class="badge bg-secondary-lt" data-module-count>0/{{ $items->count() }}</span>
                                    </button>
                                    <label class="form-check form-check-inline mb-0 ms-2 flex-shrink-0" title="Select or clear the whole module">
                                        <input type="checkbox" class="form-check-input module-toggle" aria-label="All {{ $module }} permissions">
                                        <span class="form-check-label small text-secondary">All</span>
                                    </label>
                                </h2>
                                <div id="{{ $moduleId }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}">
                                    <div class="accordion-body pt-0">
                                        <div class="row g-1">
                                            @foreach ($items as $permission)
                                                @php $locked = !$isSuperActor && !in_array($permission->slug, $grantable, true); @endphp
                                                <div class="col-md-6 permission-entry" data-search="{{ strtolower($permission->slug.' '.$permission->name.' '.$permission->description) }}">
                                                    <label class="form-check form-switch permission-item mb-0 {{ $locked ? 'is-locked' : '' }}">
                                                        <input class="form-check-input permission-box" type="checkbox" name="permissions[]" value="{{ $permission->slug }}"
                                                               @checked(in_array($permission->slug, $selected, true)) @disabled($locked)>
                                                        <span class="form-check-label d-block">
                                                            <span class="fw-medium">{{ $permission->name }}</span>
                                                            @if ($locked)<i class="ti ti-lock text-secondary ms-1" title="You do not have this permission"></i>@endif
                                                            <span class="d-block small text-secondary">{{ $permission->description }}</span>
                                                            <code class="small">{{ $permission->slug }}</code>
                                                        </span>
                                                    </label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    // Tabler bundles Bootstrap and exposes its components on window.tabler.
    const Collapse = (window.bootstrap || window.tabler || {}).Collapse;
    const total = {{ $total }};
    const editable = (scope) => Array.from(scope.querySelectorAll('.permission-box:not(:disabled)'));
    const visible = (box) => !box.closest('.permission-entry').classList.contains('d-none');

    function refresh() {
        let selected = 0;
        document.querySelectorAll('.permission-module').forEach(function (module) {
            const boxes = module.querySelectorAll('.permission-box');
            const on = Array.from(boxes).filter(b => b.checked).length;
            selected += on;
            const badge = module.querySelector('[data-module-count]');
            badge.textContent = on + '/' + boxes.length;
            badge.className = 'badge ' + (on === boxes.length ? 'bg-green-lt' : (on ? 'bg-yellow-lt' : 'bg-secondary-lt'));
            const toggle = module.querySelector('.module-toggle');
            const free = editable(module);
            toggle.disabled = free.length === 0;
            toggle.checked = free.length > 0 && free.every(b => b.checked);
            toggle.indeterminate = !toggle.checked && free.some(b => b.checked);
            const summary = document.querySelector('[data-summary="' + module.dataset.module + '"] [data-summary-count]');
            if (summary) summary.textContent = on;
        });
        const totalEl = document.getElementById('selected-total');
        if (totalEl) { totalEl.textContent = selected; document.getElementById('selected-bar').style.width = (total ? selected * 100 / total : 0) + '%'; }
    }

    document.querySelectorAll('.permission-box').forEach(b => b.addEventListener('change', refresh));
    document.querySelectorAll('.module-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', e => e.stopPropagation());
        toggle.addEventListener('change', function () {
            editable(toggle.closest('.permission-module')).filter(visible).forEach(b => b.checked = toggle.checked);
            refresh();
        });
    });
    document.querySelectorAll('[data-select]').forEach(btn => btn.addEventListener('click', function () {
        editable(document).filter(visible).forEach(b => b.checked = btn.dataset.select === 'all');
        refresh();
    }));
    const setOpen = (open) => document.querySelectorAll('#permission-modules .accordion-collapse').forEach(el => Collapse.getOrCreateInstance(el, {toggle: false})[open ? 'show' : 'hide']());
    document.querySelectorAll('[data-expand]').forEach(b => b.addEventListener('click', () => setOpen(b.dataset.expand === '1')));

    const search = document.getElementById('permission-search');
    search && search.addEventListener('input', function () {
        const q = search.value.trim().toLowerCase();
        document.querySelectorAll('.permission-module').forEach(function (module) {
            let hits = 0;
            module.querySelectorAll('.permission-entry').forEach(entry => { const hit = q === '' || entry.dataset.search.includes(q); entry.classList.toggle('d-none', !hit); hits += hit ? 1 : 0; });
            module.classList.toggle('d-none', hits === 0);
            if (q !== '' && hits) Collapse.getOrCreateInstance(module.querySelector('.accordion-collapse'), {toggle: false}).show();
        });
    });
    refresh();
})();
</script>
@endpush
