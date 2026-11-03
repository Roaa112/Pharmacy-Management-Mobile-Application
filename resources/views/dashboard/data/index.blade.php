@extends('admin.layout')

@section('title', 'Import & export')
@section('pretitle', 'Operations')
@section('subtitle', 'Validated, previewed imports and filtered exports. You only see the data you are allowed to move.')

@section('content')
@include('dashboard.data._nav')

<div class="row row-cards">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="ti ti-file-import me-2 text-secondary"></i>Import</h3></div>
            @if ($importable === [])
                <div class="card-body text-secondary">You have no import permission.</div>
            @else
                <form method="POST" action="{{ route('dashboard.data.imports.store') }}" enctype="multipart/form-data" class="card-body">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label required">What to import</label>
                        <select name="entity" class="form-select" id="import-entity">
                            @foreach ($importable as $key => $entity)<option value="{{ $key }}" data-modes="{{ implode(',', $entity->modes()) }}" @selected(old('entity') === $key)>{{ $entity->label() }}</option>@endforeach
                        </select>
                        @foreach ($importable as $key => $entity)
                            <small class="form-hint import-hint" data-entity="{{ $key }}">{{ $entity->description() }} {{ $entity->matchDescription() }}
                                <a href="{{ route('dashboard.data.template', [$key, 'format' => 'xlsx']) }}">XLSX template</a> ·
                                <a href="{{ route('dashboard.data.template', [$key, 'format' => 'csv']) }}">CSV template</a></small>
                        @endforeach
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Mode</label>
                        <select name="mode" class="form-select" id="import-mode">
                            @foreach ($modes as $mode => $label)<option value="{{ $mode }}" @selected(old('mode', 'upsert') === $mode)>{{ $label }}</option>@endforeach
                        </select>
                        <small class="form-hint">Create only rejects records that exist; update only rejects records that do not.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">File (CSV or XLSX, up to 20 MB)</label>
                        <input type="file" name="file" class="form-control" accept=".csv,.xlsx,text/csv" required>
                    </div>
                    <button class="btn btn-primary"><i class="ti ti-upload me-1"></i>Upload and validate</button>
                    <small class="form-hint mt-2">Nothing is written until you have seen the preview and confirmed it.</small>
                </form>
            @endif
        </div>

        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">What can be moved</h3></div>
            <div class="table-responsive">
                <table class="table table-sm card-table">
                    <thead><tr><th>Data</th><th class="text-center">Import</th><th class="text-center">Export</th></tr></thead>
                    <tbody>
                    @foreach ($entities as $key => $entity)
                        <tr>
                            <td>{{ $entity->label() }}@if ($entity->sensitive()) <span class="badge bg-orange-lt ms-1" title="Personal or financial data: explicit permission, every export audited">sensitive</span>@endif</td>
                            <td class="text-center">@if ($entity->canImport())<i class="ti ti-check {{ isset($importable[$key]) ? 'text-green' : 'text-secondary' }}"></i>@else<span class="text-secondary">—</span>@endif</td>
                            <td class="text-center">@if ($entity->canExport())<i class="ti ti-check {{ isset($exportable[$key]) ? 'text-green' : 'text-secondary' }}"></i>@else<span class="text-secondary">—</span>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-secondary">Grey ticks: supported, but your role does not allow it.</div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="ti ti-file-export me-2 text-secondary"></i>Export</h3>
                @if ($exportable !== [])
                    <div class="card-actions">
                        <form method="GET"><select name="export" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Data to export">
                            @foreach ($exportable as $key => $entity)<option value="{{ $key }}" @selected($selectedExport?->key() === $key)>{{ $entity->label() }}</option>@endforeach
                        </select></form>
                    </div>
                @endif
            </div>
            @if (!$selectedExport)
                <div class="card-body text-secondary">You have no export permission.</div>
            @else
                <form method="POST" action="{{ route('dashboard.data.exports.store') }}" class="card-body">
                    @csrf
                    <input type="hidden" name="entity" value="{{ $selectedExport->key() }}">
                    <p class="text-secondary">{{ $selectedExport->description() }}</p>
                    @if ($selectedExport->filters())
                        <div class="row">
                            @foreach ($selectedExport->filters() as $key => $filter)
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <label class="form-label">{{ $filter[0] }}</label>
                                    @if ($filter[1] === 'select')
                                        <select name="filters[{{ $key }}]" class="form-select"><option value="">Any</option>
                                            @foreach ($filter[2] ?? [] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                        </select>
                                    @elseif ($filter[1] === 'boolean')
                                        <select name="filters[{{ $key }}]" class="form-select"><option value="">No</option><option value="1">Yes</option></select>
                                    @else
                                        <input type="{{ $filter[1] === 'date' ? 'date' : ($filter[1] === 'number' ? 'number' : 'text') }}" step="any" name="filters[{{ $key }}]" class="form-control">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="mb-3">
                        <div class="d-flex align-items-center mb-1"><label class="form-label mb-0">Columns</label>
                            <button type="button" class="btn btn-sm btn-ghost-primary ms-auto" data-columns="all">All</button>
                            <button type="button" class="btn btn-sm btn-ghost-secondary" data-columns="none">None</button></div>
                        <div class="row">
                            @foreach ($selectedExport->columns() as $key => $column)
                                <div class="col-sm-6 col-lg-4"><label class="form-check" title="{{ $column->description }}">
                                    <input type="checkbox" class="form-check-input export-column" name="columns[]" value="{{ $key }}" @checked($column->defaultExport)>
                                    <span class="form-check-label">{{ $column->label }} <code class="small text-secondary">{{ $key }}</code></span>
                                </label></div>
                            @endforeach
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <select name="format" class="form-select w-auto" aria-label="Format"><option value="xlsx">XLSX</option><option value="csv">CSV</option></select>
                        <button class="btn btn-primary"><i class="ti ti-download me-1"></i>Export</button>
                        @if ($selectedExport->sensitive())<span class="small text-orange">Sensitive data: this export is recorded in the audit log.</span>@endif
                    </div>
                </form>
            @endif
        </div>

        <div class="row row-cards mt-0">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Latest imports</h3><div class="card-actions"><a href="{{ route('dashboard.data.imports.index') }}">All</a></div></div>
                    <div class="list-group list-group-flush">
                        @forelse ($recentImports as $run)
                            <a href="{{ route('dashboard.data.imports.show', $run) }}" class="list-group-item list-group-item-action small d-flex">
                                <span>#{{ $run->id }} {{ $entities[$run->entity]?->label() ?? $run->entity }}<span class="d-block text-secondary">{{ $run->original_name }}</span></span>
                                <x-admin.status class="ms-auto align-self-center" :status="$run->awaitingConfirmation() ? 'scheduled' : $run->status" />
                            </a>
                        @empty
                            <div class="list-group-item small text-secondary">No imports yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Latest exports</h3><div class="card-actions"><a href="{{ route('dashboard.data.exports.index') }}">All</a></div></div>
                    <div class="list-group list-group-flush">
                        @forelse ($recentExports as $run)
                            <div class="list-group-item small d-flex">
                                <span>#{{ $run->id }} {{ $entities[$run->entity]?->label() ?? $run->entity }} · {{ strtoupper($run->format) }}<span class="d-block text-secondary">{{ number_format($run->row_count) }} rows · {{ $run->created_at->diffForHumans() }}</span></span>
                                @if ($run->status === 'completed' && isset($exportable[$run->entity]))<a class="ms-auto align-self-center" href="{{ route('dashboard.data.exports.download', $run) }}">Download</a>@else<x-admin.status class="ms-auto align-self-center" :status="$run->status" />@endif
                            </div>
                        @empty
                            <div class="list-group-item small text-secondary">No exports yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const entity = document.getElementById('import-entity'), mode = document.getElementById('import-mode');
    const sync = () => {
        if (!entity) return;
        const opt = entity.selectedOptions[0], modes = (opt.dataset.modes || '').split(',');
        document.querySelectorAll('.import-hint').forEach(h => h.classList.toggle('d-none', h.dataset.entity !== entity.value));
        Array.from(mode.options).forEach(o => { o.disabled = !modes.includes(o.value); });
        if (mode.selectedOptions[0].disabled) mode.value = modes[0];
    };
    entity && entity.addEventListener('change', sync); sync();
    document.querySelectorAll('[data-columns]').forEach(b => b.addEventListener('click', () => document.querySelectorAll('.export-column').forEach(c => c.checked = b.dataset.columns === 'all')));
})();
</script>
@endpush
