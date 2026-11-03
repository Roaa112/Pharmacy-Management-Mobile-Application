@extends('admin.layout')

@section('title', 'Expiring points')
@section('pretitle', 'Loyalty')
@section('subtitle', 'Usable points about to lapse, and points that already expired. Expired points can never be redeemed.')

@section('content')
@include('dashboard.loyalty._tabs')

<div class="card mb-3">
    <div class="card-header">
        <h3 class="card-title">Expiring within {{ $days }} days · {{ number_format($upcomingTotal) }} points</h3>
        <div class="card-actions">
            <form method="GET" class="d-flex gap-2">
                <input type="number" name="days" min="1" max="365" value="{{ $days }}" class="form-control form-control-sm" style="width: 6rem" aria-label="Days">
                <button class="btn btn-sm btn-primary">Show</button>
            </form>
        </div>
    </div>
    @if ($upcoming->isEmpty())
        <x-admin.empty icon="clock-check" title="Nothing expires in this period" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table table-sm">
                <thead><tr><th>Customer</th><th>From</th><th class="text-end">Points left</th><th>Expires</th></tr></thead>
                <tbody>
                @foreach ($upcoming as $lot)
                    <tr>
                        <td><a href="{{ route('dashboard.customers.show', $lot->user_id) }}">{{ $lot->user?->name ?? '#'.$lot->user_id }}</a></td>
                        <td class="small">{{ $lot->description }} <span class="text-secondary">({{ ucfirst($lot->type) }})</span></td>
                        <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($lot->remaining_points) }}</span></td>
                        <td class="small">{{ $lot->expires_at->format('M j, Y H:i') }} <span class="text-secondary">({{ $lot->expires_at->diffForHumans() }})</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$upcoming" />
    @endif
</div>

<div class="card">
    <div class="card-header"><h3 class="card-title">Expiration history</h3></div>
    @include('dashboard.loyalty._entries', ['entries' => $expired, 'showCustomer' => true])
    <x-admin.pagination :paginator="$expired" />
</div>
@endsection
