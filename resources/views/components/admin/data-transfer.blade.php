{{--
    Import / export of the data shown on this page. Usage, in the page's actions:
        <x-admin.data-transfer :entities="['products']" />
    Several entities live on one page (cities and areas, orders and order lines): pass them all.
    Only what the admin may import or export is offered; the server checks again on every action.
    Export filters start from the page's own query string when the names match.
--}}
@props(['entities'])
@php
    $admin = auth('admin')->user();
    $definitions = collect($entities)->map(fn ($key) => \App\Modules\DataTransfer\EntityRegistry::find($key))->filter();
    $imports = $definitions->filter(fn ($e) => $e->canImport() && $admin?->hasPermission($e->importPermission()))->keyBy(fn ($e) => $e->key());
    $exports = $definitions->filter(fn ($e) => $e->canExport() && $admin?->hasPermission($e->exportPermission()))->keyBy(fn ($e) => $e->key());
    $visible = $definitions->filter(fn ($e) => $imports->has($e->key()) || $exports->has($e->key()));
    $id = 'dt-'.implode('-', $entities);
    $modes = \App\Models\ImportRun::MODES;
@endphp

@if ($visible->isNotEmpty())
    <div class="btn-group" role="group" aria-label="Import and export">
        @if ($imports->isNotEmpty())
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $id }}-import"><i class="ti ti-file-import me-1"></i>Import</button>
        @endif
        @if ($exports->isNotEmpty())
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $id }}-export"><i class="ti ti-file-export me-1"></i>Export</button>
        @endif
        <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More import and export options"></button>
        <div class="dropdown-menu dropdown-menu-end">
            @foreach ($visible as $entity)
                @if ($visible->count() > 1)<h6 class="dropdown-header">{{ $entity->label() }}</h6>@endif
                @if ($imports->has($entity->key()))
                    <a class="dropdown-item" href="{{ route('dashboard.data.template', [$entity->key(), 'format' => 'xlsx']) }}"><i class="ti ti-file-spreadsheet me-2"></i>Import template (XLSX)</a>
                    <a class="dropdown-item" href="{{ route('dashboard.data.template', [$entity->key(), 'format' => 'csv']) }}"><i class="ti ti-file-text me-2"></i>Import template (CSV)</a>
                @endif
                <a class="dropdown-item" href="{{ route('dashboard.data.history', $entity->key()) }}"><i class="ti ti-history me-2"></i>Import & export history</a>
                @unless ($loop->last)<div class="dropdown-divider"></div>@endunless
            @endforeach
        </div>
    </div>

    @push('modals')
        @if ($imports->isNotEmpty())
            <x-admin.modal :id="$id.'-import'" :title="'Import '.$imports->map->label()->implode(' / ')" :action="route('dashboard.data.imports.store')" files submit="Upload and check">
                @if ($imports->count() > 1)
                    <div class="mb-3">
                        <label class="form-label required">What to import</label>
                        <select name="entity" class="form-select" data-dt-switch="{{ $id }}-import">
                            @foreach ($imports as $key => $entity)<option value="{{ $key }}" data-modes="{{ implode(',', $entity->modes()) }}">{{ $entity->label() }}</option>@endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="entity" value="{{ $imports->keys()->first() }}">
                @endif

                @foreach ($imports as $key => $entity)
                    <div class="alert alert-info small mb-3" data-dt-panel="{{ $id }}-import" data-entity="{{ $key }}" data-modes="{{ implode(',', $entity->modes()) }}">
                        <div class="mb-1">{{ $entity->matchDescription() }}</div>
                        Start from the template:
                        <a href="{{ route('dashboard.data.template', [$key, 'format' => 'xlsx']) }}">XLSX</a> ·
                        <a href="{{ route('dashboard.data.template', [$key, 'format' => 'csv']) }}">CSV</a>
                    </div>
                @endforeach

                <div class="mb-3">
                    <label class="form-label required">Mode</label>
                    <select name="mode" class="form-select" data-dt-modes="{{ $id }}-import">
                        @foreach ($modes as $mode => $label)<option value="{{ $mode }}" @selected($mode === 'upsert')>{{ $label }}</option>@endforeach
                    </select>
                    <small class="form-hint">Create only rejects rows that already exist; update only rejects rows that do not.</small>
                </div>
                <div class="mb-0">
                    <label class="form-label required">File</label>
                    <input type="file" name="file" class="form-control" accept=".csv,.xlsx,text/csv" required>
                    <small class="form-hint">CSV or XLSX, up to 20 MB. Nothing is saved until you have seen the check results and confirmed them.</small>
                </div>
            </x-admin.modal>
        @endif

        @if ($exports->isNotEmpty())
            <x-admin.modal :id="$id.'-export'" :title="'Export '.$exports->map->label()->implode(' / ')" :action="route('dashboard.data.exports.store')" size="lg" submit="Export">
                @if ($exports->count() > 1)
                    <div class="mb-3">
                        <label class="form-label required">What to export</label>
                        <select name="entity" class="form-select" data-dt-switch="{{ $id }}-export">
                            @foreach ($exports as $key => $entity)<option value="{{ $key }}">{{ $entity->label() }}</option>@endforeach
                        </select>
                    </div>
                @else
                    <input type="hidden" name="entity" value="{{ $exports->keys()->first() }}">
                @endif

                @foreach ($exports as $key => $entity)
                    <div data-dt-panel="{{ $id }}-export" data-entity="{{ $key }}">
                        @if ($entity->sensitive())
                            <div class="alert alert-warning small"><i class="ti ti-shield-lock me-1"></i>Personal or financial data. Only the listed columns are exported, never passwords or secrets, and the export is recorded in the audit log.</div>
                        @endif
                        @if ($entity->filters())
                            <div class="fw-medium mb-2">Filters</div>
                            <div class="row">
                                @foreach ($entity->filters() as $filterKey => $filter)
                                    @php $current = request()->query($filterKey); @endphp
                                    <div class="col-sm-6 col-lg-4 mb-3">
                                        <label class="form-label small mb-1">{{ $filter[0] }}</label>
                                        @if ($filter[1] === 'select')
                                            <select name="filters[{{ $filterKey }}]" class="form-select form-select-sm"><option value="">Any</option>
                                                @foreach ($filter[2] ?? [] as $value => $label)<option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>@endforeach
                                            </select>
                                        @elseif ($filter[1] === 'boolean')
                                            <select name="filters[{{ $filterKey }}]" class="form-select form-select-sm"><option value="">No</option><option value="1" @selected($current)>Yes</option></select>
                                        @else
                                            <input type="{{ $filter[1] === 'date' ? 'date' : ($filter[1] === 'number' ? 'number' : 'text') }}" step="any" name="filters[{{ $filterKey }}]" value="{{ is_scalar($current) ? $current : '' }}" class="form-control form-control-sm">
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="d-flex align-items-center mb-2">
                            <span class="fw-medium">Columns</span>
                            <button type="button" class="btn btn-sm btn-link ms-auto" data-dt-columns="all">All</button>
                            <button type="button" class="btn btn-sm btn-link" data-dt-columns="none">None</button>
                        </div>
                        <div class="row border rounded py-2 mx-0 mb-3" style="max-height: 16rem; overflow-y: auto">
                            @foreach ($entity->columns() as $columnKey => $column)
                                <div class="col-sm-6 col-lg-4"><label class="form-check mb-1" title="{{ $column->description }}">
                                    <input type="checkbox" class="form-check-input" name="columns[]" value="{{ $columnKey }}" @checked($column->defaultExport)>
                                    <span class="form-check-label small">{{ $column->label }}</span>
                                </label></div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0">Format</label>
                    <select name="format" class="form-select w-auto"><option value="xlsx">Excel (XLSX)</option><option value="csv">CSV</option></select>
                    <small class="text-secondary ms-auto">Large exports are prepared in the background and appear in the history.</small>
                </div>
            </x-admin.modal>
        @endif
    @endpush

    @once
        @push('scripts')
            <script>
            (function () {
                // Show the panel of the chosen entity; hidden panels' fields are disabled so they are not sent.
                function sync(select) {
                    const group = select.dataset.dtSwitch;
                    document.querySelectorAll('[data-dt-panel="' + group + '"]').forEach(function (panel) {
                        const on = panel.dataset.entity === select.value;
                        panel.classList.toggle('d-none', !on);
                        panel.querySelectorAll('input, select').forEach(el => el.disabled = !on);
                    });
                    const modes = document.querySelector('[data-dt-modes="' + group + '"]');
                    const option = select.selectedOptions[0];
                    if (modes && option && option.dataset.modes) {
                        const allowed = option.dataset.modes.split(',');
                        Array.from(modes.options).forEach(o => o.disabled = !allowed.includes(o.value));
                        if (modes.selectedOptions[0].disabled) modes.value = allowed[0];
                    }
                }
                document.querySelectorAll('[data-dt-switch]').forEach(function (select) {
                    select.addEventListener('change', () => sync(select));
                    sync(select);
                });
                // Single-entity import with a restricted mode list (e.g. inventory: update only).
                document.querySelectorAll('[data-dt-modes]').forEach(function (modes) {
                    const group = modes.dataset.dtModes;
                    if (document.querySelector('[data-dt-switch="' + group + '"]')) return;
                    const panel = document.querySelector('[data-dt-panel="' + group + '"]');
                    if (!panel) return;
                    const allowed = panel.dataset.modes.split(',');
                    Array.from(modes.options).forEach(o => o.disabled = !allowed.includes(o.value));
                    if (modes.selectedOptions[0].disabled) modes.value = allowed[0];
                });
                document.querySelectorAll('[data-dt-columns]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        button.closest('[data-dt-panel]').querySelectorAll('input[name="columns[]"]').forEach(c => c.checked = button.dataset.dtColumns === 'all');
                    });
                });
            })();
            </script>
        @endpush
    @endonce
@endif
