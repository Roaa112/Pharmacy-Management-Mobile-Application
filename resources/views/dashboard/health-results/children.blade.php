@extends('admin.layout')

@section('title', 'Children & vaccines')
@section('pretitle', 'Customer health data')

@section('content')
<div class="alert alert-warning">
    <div class="d-flex gap-2"><i class="ti ti-lock fs-2"></i>
        <div>Information about customers' children. Use it only to support the customer, and do not export or share it.</div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <form method="GET" class="d-flex gap-2 w-100" style="max-width: 28rem">
            <div class="input-icon flex-fill">
                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                <input type="search" name="search" class="form-control" placeholder="Customer name" value="{{ request('search') }}">
            </div>
            <button class="btn btn-primary">Search</button>
        </form>
    </div>
    @if ($children->isEmpty())
        <x-admin.empty icon="mood-kid" title="No records" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Customer</th><th>Child</th><th>Born</th><th>Vaccines</th></tr></thead>
                <tbody>
                @foreach ($children as $child)
                    @php
                        $done = $child->vaccines->where('is_completed', true)->count();
                        $total = $child->vaccines->count();
                    @endphp
                    <tr>
                        <td>
                            @if ($child->user)
                                <a href="{{ route('dashboard.customers.show', $child->user_id) }}" class="text-reset fw-medium">{{ $child->user->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $child->child_name }} <span class="text-secondary small">· {{ $child->gender }}</span></td>
                        <td class="small">{{ \Carbon\Carbon::parse($child->birth_date)->format('M j, Y') }}</td>
                        <td style="min-width: 14rem">
                            @if ($total)
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <div class="progress progress-sm flex-fill"><div class="progress-bar bg-teal" style="width: {{ round($done / $total * 100) }}%"></div></div>
                                    <span class="small text-secondary">{{ $done }}/{{ $total }}</span>
                                </div>
                                <details class="small">
                                    <summary class="text-secondary">Schedule</summary>
                                    <ul class="list-unstyled mt-1 mb-0">
                                        @foreach ($child->vaccines as $vaccine)
                                            <li class="d-flex justify-content-between gap-2">
                                                <span>{{ $vaccine->vaccine_name }}</span>
                                                <span class="text-nowrap">{{ \Carbon\Carbon::parse($vaccine->scheduled_date)->format('M j, Y') }}
                                                    <i class="ti {{ $vaccine->is_completed ? 'ti-circle-check text-green' : 'ti-clock text-secondary' }}"></i></span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </details>
                            @else
                                <span class="text-secondary small">No vaccines</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$children->withQueryString()" />
    @endif
</div>
@endsection
