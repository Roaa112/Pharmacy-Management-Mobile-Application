@extends('admin.layout')

@section('title', $role->exists ? 'Edit role: '.$role->name : 'New role')
@section('pretitle', 'Roles & permissions')

@section('actions')
    <a href="{{ route('dashboard.roles.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Roles</a>
@endsection

@section('content')
@php $super = $role->is_super_admin; $isSuperActor = auth('admin')->user()->isSuperAdmin(); @endphp
<form method="POST" action="{{ $role->exists ? route('dashboard.roles.update', $role) : route('dashboard.roles.store') }}">
    @csrf
    @if ($role->exists) @method('PUT') @endif

    <div class="row row-cards">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <x-admin.input name="name" label="Role name" :value="old('name', $role->name)" required maxlength="80" />
                    <x-admin.input type="textarea" name="description" label="Description" :value="old('description', $role->description)" maxlength="255" />
                    @unless ($super)
                        <label class="form-check form-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', $role->is_active ?? true))>
                            <span class="form-check-label">Active</span>
                        </label>
                    @endunless
                    @if ($role->exists)<p class="small text-secondary mb-0">Identifier: <code>{{ $role->slug }}</code> (never changes)</p>@endif
                </div>
                <div class="card-footer d-flex gap-2">
                    <button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save role</button>
                    <span class="ms-auto small text-secondary align-self-center"><span id="selected-total">0</span> selected</span>
                </div>
            </div>
            @unless ($isSuperActor)
                <div class="alert alert-info mt-3 small">You can only grant permissions you have yourself; the others are locked.</div>
            @endunless
        </div>

        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Permissions</h3>
                    @unless ($super)
                        <div class="card-actions d-flex flex-wrap gap-2">
                            <input type="search" id="permission-search" class="form-control form-control-sm" placeholder="Search permissions…" aria-label="Search permissions" style="max-width: 14rem">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-select="all">Select all</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-select="none">Clear all</button>
                        </div>
                    @endunless
                </div>
                <div class="card-body">
                    @if ($super)
                        <p class="mb-0">The super admin role always has every permission. Only its name and description can be changed.</p>
                    @else
                        @foreach ($permissions as $module => $items)
                            <fieldset class="permission-group border rounded p-3 mb-3">
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <legend class="fs-4 fw-bold mb-0 w-auto">{{ \App\Modules\Admin\Authorization\PermissionRegistry::moduleLabel($module) }}</legend>
                                    <button type="button" class="btn btn-sm btn-ghost-primary ms-auto" data-group-select="all">Select all</button>
                                    <button type="button" class="btn btn-sm btn-ghost-secondary" data-group-select="none">Clear</button>
                                </div>
                                <div class="row">
                                    @foreach ($items as $permission)
                                        @php $locked = !$isSuperActor && !in_array($permission->slug, $grantable, true); @endphp
                                        <div class="col-md-6 permission-item" data-search="{{ strtolower($permission->slug.' '.$permission->name.' '.$permission->description) }}">
                                            <label class="form-check" title="{{ $permission->description }}">
                                                <input class="form-check-input permission-box" type="checkbox" name="permissions[]" value="{{ $permission->slug }}"
                                                       @checked(in_array($permission->slug, $selected, true)) @disabled($locked)>
                                                <span class="form-check-label">{{ $permission->name }} <code class="small text-secondary">{{ $permission->slug }}</code>
                                                    @if ($locked)<i class="ti ti-lock text-secondary" title="You do not have this permission"></i>@endif</span>
                                            </label>
                                        </div>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const boxes = () => Array.from(document.querySelectorAll('.permission-box:not(:disabled)'));
    const visible = (el) => !el.closest('.permission-item').classList.contains('d-none');
    const total = () => { const el = document.getElementById('selected-total'); if (el) el.textContent = document.querySelectorAll('.permission-box:checked').length; };
    document.querySelectorAll('[data-select]').forEach(btn => btn.addEventListener('click', () => {
        boxes().filter(visible).forEach(b => b.checked = btn.dataset.select === 'all'); total();
    }));
    document.querySelectorAll('[data-group-select]').forEach(btn => btn.addEventListener('click', () => {
        btn.closest('.permission-group').querySelectorAll('.permission-box:not(:disabled)').forEach(b => { if (visible(b)) b.checked = btn.dataset.groupSelect === 'all'; }); total();
    }));
    document.querySelectorAll('.permission-box').forEach(b => b.addEventListener('change', total));
    const search = document.getElementById('permission-search');
    search && search.addEventListener('input', () => {
        const q = search.value.trim().toLowerCase();
        document.querySelectorAll('.permission-item').forEach(item => item.classList.toggle('d-none', q !== '' && !item.dataset.search.includes(q)));
        document.querySelectorAll('.permission-group').forEach(group => group.classList.toggle('d-none', !group.querySelector('.permission-item:not(.d-none)')));
    });
    total();
})();
</script>
@endpush
