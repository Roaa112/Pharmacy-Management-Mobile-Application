{{--
    Shared layout for the customer health result pages.
    Expects: $title, $rows (paginator), $columns = [label => fn ($row) => value]
--}}
@extends('admin.layout')

@section('title', $title)
@section('pretitle', 'Customer health data')

@section('content')
<div class="alert alert-warning">
    <div class="d-flex gap-2"><i class="ti ti-lock fs-2"></i>
        <div>Personal health information entered by customers in the app. Use it only to support the customer, and do not export or share it.</div>
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
    @if ($rows->isEmpty())
        <x-admin.empty icon="heart-off" title="No records" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Customer</th>@foreach (array_keys($columns) as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            @if ($row->user)
                                <a href="{{ route('dashboard.customers.show', $row->user_id) }}" class="text-reset fw-medium">{{ $row->user->name }}</a>
                            @else
                                —
                            @endif
                        </td>
                        @foreach ($columns as $value)
                            <td>{{ $value($row) ?? '—' }}</td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$rows->withQueryString()" />
    @endif
</div>
@endsection
