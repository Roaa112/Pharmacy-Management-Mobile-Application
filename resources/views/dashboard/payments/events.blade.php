@extends('admin.layout')

@section('title', 'Provider events')
@section('pretitle', 'Payments')
@section('subtitle', 'Every payment notification received, stored before it is acted on. Rejected events (bad signature, mismatched amount) were never applied.')

@section('actions')
    <a href="{{ route('dashboard.payments.index') }}" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>Payments</a>
@endsection

@section('content')
<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2">
            <select name="result" class="form-select w-auto" aria-label="Result"><option value="">All results</option>@foreach (['received', 'processed', 'rejected', 'failed', 'ignored'] as $r)<option @selected(request('result') === $r)>{{ $r }}</option>@endforeach</select>
            <select name="provider" class="form-select w-auto" aria-label="Provider"><option value="">All providers</option>@foreach (['paymob', 'cod', 'manual_transfer'] as $p)<option @selected(request('provider') === $p)>{{ $p }}</option>@endforeach</select>
            <button class="btn btn-outline-primary">Filter</button>
        </form>
    </div>
    @if ($events->isEmpty())
        <x-admin.empty icon="webhook" title="No events" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-sm">
                <thead><tr><th>When</th><th>Provider</th><th>Event</th><th>Payment</th><th>Signature</th><th>Result</th><th>Error</th></tr></thead>
                @foreach ($events as $e)
                    <tr>
                        <td class="small text-nowrap">{{ $e->created_at->format('Y-m-d H:i:s') }}</td>
                        <td>{{ $e->provider }}</td>
                        <td class="small">{{ $e->event_type }}<div class="text-secondary">{{ $e->provider_event_id }}</div></td>
                        <td>@if ($e->payment)<a href="{{ route('dashboard.payments.show', $e->payment) }}">{{ $e->payment->internal_reference }}</a>@else — @endif</td>
                        <td>@if ($e->signature_valid === null) — @elseif ($e->signature_valid)<i class="ti ti-check text-green"></i>@else<i class="ti ti-x text-red"></i>@endif</td>
                        <td><span class="badge bg-{{ ['processed' => 'green', 'rejected' => 'red', 'failed' => 'red', 'ignored' => 'secondary'][$e->result] ?? 'blue' }}-lt">{{ $e->result }}</span></td>
                        <td class="small text-secondary">{{ \Illuminate\Support\Str::limit($e->error, 90) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <x-admin.pagination :paginator="$events" />
    @endif
</div>
@endsection
