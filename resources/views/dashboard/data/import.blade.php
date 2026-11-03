@extends('admin.layout')

@section('title', 'Import #'.$run->id.' · '.$entity->label())
@section('pretitle', 'Import & export')
@section('subtitle', $run->original_name.' · '.($modes[$run->mode] ?? $run->mode).' · uploaded by '.($run->admin?->name ?? '—').' '.$run->created_at->diffForHumans())

@section('actions')
    @if ($run->errors_path)<a href="{{ route('dashboard.data.imports.file', [$run, 'errors']) }}" class="btn btn-outline-secondary"><i class="ti ti-alert-triangle me-1"></i>Error report</a>@endif
    @if ($run->results_path)<a href="{{ route('dashboard.data.imports.file', [$run, 'results']) }}" class="btn btn-outline-secondary"><i class="ti ti-list-check me-1"></i>Result report</a>@endif
    <a href="{{ route('dashboard.data.imports.file', [$run, 'original']) }}" class="btn btn-outline-secondary"><i class="ti ti-file me-1"></i>Original file</a>
@endsection

@section('content')
@include('dashboard.data._nav')
@php $s = $run->summary ?? []; $processed = $s['processed'] ?? null; @endphp

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div><span class="text-secondary small d-block">Status</span>
            @if ($run->awaitingConfirmation())<span class="badge bg-blue-lt">Validated: waiting for confirmation</span>
            @elseif ($run->status === 'pending')<span class="badge bg-orange-lt">Validating…</span>
            @else<x-admin.status :status="$run->status" />@endif
        </div>
        @if ($run->started_at)<div><span class="text-secondary small d-block">Started</span>{{ $run->started_at->format('M j, Y H:i') }}</div>@endif
        @if ($run->completed_at)<div><span class="text-secondary small d-block">Finished</span>{{ $run->completed_at->format('M j, Y H:i') }}</div>@endif
        <div class="ms-auto d-flex gap-2">
            @if ($run->awaitingConfirmation())
                <form method="POST" action="{{ route('dashboard.data.imports.confirm', $run) }}" data-confirm="Import {{ number_format($run->valid_rows) }} valid rows? Invalid rows are skipped and reported.">@csrf
                    <button class="btn btn-primary" @disabled($run->valid_rows === 0)><i class="ti ti-player-play me-1"></i>Confirm import</button></form>
            @endif
            @if (in_array($run->status, ['pending', 'processing'], true))
                <form method="POST" action="{{ route('dashboard.data.imports.cancel', $run) }}" data-confirm="Cancel this import?">@csrf<button class="btn btn-outline-danger">Cancel</button></form>
            @endif
        </div>
    </div>
    @if ($run->failure_message)<div class="card-footer text-red">{{ $run->failure_message }}</div>@endif
</div>

<div class="row row-cards mb-3">
    <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="Total rows" icon="table" :value="number_format($run->total_rows)" /></div>
    <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="Valid rows" icon="circle-check" tone="green" :value="number_format($run->valid_rows)" /></div>
    <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="Invalid rows" icon="circle-x" tone="red" :value="number_format($run->invalid_rows)" /></div>
    <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="Warnings" icon="alert-triangle" tone="orange" :value="number_format($run->warning_count)" /></div>
    @if ($run->isFinished() && $run->status !== 'failed' || $run->status === 'processing')
        <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="Created" icon="plus" tone="teal" :value="number_format($run->created_count)" /></div>
        <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="Updated" icon="refresh" tone="blue" :value="number_format($run->updated_count)" /></div>
    @else
        <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="To create" icon="plus" tone="teal" :value="number_format($run->to_create)" /></div>
        <div class="col-6 col-md-3 col-xl-2"><x-admin.stat label="To update" icon="refresh" tone="blue" :value="number_format($run->to_update)" /></div>
    @endif
</div>

@if ($s)
    <div class="row row-cards">
        <div class="col-lg-4">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>Records ({{ $entity->label() }})</span><strong>{{ number_format($s['records'] ?? 0) }}</strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>Duplicate records in the file</span><strong>{{ number_format($s['duplicates'] ?? 0) }}</strong></div>
                    <div class="d-flex justify-content-between mb-2"><span>Relationship errors</span><strong>{{ number_format($s['relationship_errors'] ?? 0) }}</strong></div>
                    @if (!empty($s['ignored_columns']))<div class="small text-secondary">Ignored columns (unknown or export only): {{ implode(', ', $s['ignored_columns']) }}</div>@endif
                    @if ($processed)<hr><div class="small text-secondary">At processing time: {{ $processed['records'] ?? 0 }} records, {{ $processed['invalid_records'] ?? 0 }} failed.</div>@endif
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            @foreach (['sample_errors' => ['Errors', 'red'], 'sample_warnings' => ['Warnings', 'orange']] as $key => [$title, $tone])
                @php $items = ($processed[$key] ?? null) ?: ($s[$key] ?? []); @endphp
                @if ($items)
                    <div class="card mb-3">
                        <div class="card-header"><h3 class="card-title text-{{ $tone }}">{{ $title }}</h3><div class="card-actions small text-secondary">First {{ count($items) }} · the full list is in the error report</div></div>
                        <div class="table-responsive" style="max-height: 24rem">
                            <table class="table table-sm card-table">
                                <thead><tr><th>Row</th><th>Column</th><th>Problem</th><th>Value</th></tr></thead>
                                <tbody>
                                @foreach ($items as $item)
                                    <tr><td>{{ $item['row'] }}</td><td><code>{{ $item['column'] }}</code></td><td>{{ $item['message'] }}</td><td class="small text-secondary text-break">{{ \Illuminate\Support\Str::limit($item['value'], 60) }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif
@endsection
