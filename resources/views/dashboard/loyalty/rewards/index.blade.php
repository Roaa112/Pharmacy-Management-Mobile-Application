@extends('admin.layout')

@section('title', 'Rewards')
@section('pretitle', 'Loyalty')
@section('subtitle', 'What customers can get for their points.')

@section('actions')
    <x-admin.data-transfer :entities="['loyalty_rewards']" />
    @can('loyalty.manage_rewards')<a href="{{ route('dashboard.loyalty.rewards.create') }}" class="btn btn-primary"><i class="ti ti-plus me-1"></i>New reward</a>@endcan
@endsection

@section('content')
@include('dashboard.loyalty._tabs')

<div class="card">
    <div class="card-header">
        <form method="GET" class="row g-2 w-100">
            <div class="col-md-5"><input type="search" name="search" class="form-control" placeholder="Title or code" value="{{ $search }}"></div>
            <div class="col-md-3">
                <select name="type" class="form-select" aria-label="Type"><option value="">All types</option>
                    @foreach ($types as $type)<option value="{{ $type }}" @selected(request('type') === $type)>{{ ucwords(str_replace('_', ' ', $type)) }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select" aria-label="Status"><option value="">Any status</option>
                    <option value="active" @selected(request('status') === 'active')>Available now</option>
                    <option value="inactive" @selected(request('status') === 'inactive')>Inactive</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary"><i class="ti ti-filter me-1"></i>Filter</button></div>
        </form>
    </div>
    @if ($rewards->isEmpty())
        <x-admin.empty icon="gift-off" title="No rewards yet" text="Create rewards such as a product, free delivery or a voucher." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Reward</th><th>Type</th><th class="text-end">Points</th><th class="text-end">Stock</th><th class="text-end">Redeemed</th><th>Limits</th><th>Status</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($rewards as $reward)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                @if ($reward->imageUrl())<span class="avatar avatar-sm" style="background-image: url('{{ $reward->imageUrl() }}')"></span>@endif
                                <div><div class="fw-medium">{{ $reward->title }}</div><code class="small text-secondary">{{ $reward->code }}</code>
                                    @if ($reward->product)<div class="small text-secondary">{{ $reward->product->name_en }}</div>@endif</div>
                            </div>
                        </td>
                        <td>{{ ucwords(str_replace('_', ' ', $reward->type)) }}</td>
                        <td class="text-end"><span class="badge bg-yellow-lt">{{ number_format($reward->points_required) }}</span></td>
                        <td class="text-end">{{ $reward->stock === null ? '∞' : number_format($reward->stock) }}</td>
                        <td class="text-end">{{ number_format($reward->redeemed_count) }}@if ($reward->open_redemptions)<div class="small text-orange">{{ $reward->open_redemptions }} open</div>@endif</td>
                        <td class="small">
                            @if ($reward->per_customer_limit){{ $reward->per_customer_limit }} / customer<br>@endif
                            @if ($reward->total_limit){{ $reward->total_limit }} in total<br>@endif
                            @if ($reward->starts_at || $reward->ends_at)<span class="text-secondary">{{ $reward->starts_at?->format('M j') ?? '…' }} – {{ $reward->ends_at?->format('M j, Y') ?? '…' }}</span>@endif
                        </td>
                        <td><x-admin.status :status="$reward->statusLabel()" /></td>
                        <td class="text-nowrap">
                            <a href="{{ route('dashboard.loyalty.redemptions.index', ['reward_id' => $reward->id]) }}" class="btn btn-sm btn-ghost-secondary btn-icon" title="Redemptions" aria-label="Redemptions"><i class="ti ti-list"></i></a>
                            @can('loyalty.manage_rewards')
                                <a href="{{ route('dashboard.loyalty.rewards.edit', $reward) }}" class="btn btn-sm btn-ghost-primary btn-icon" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></a>
                                <form action="{{ route('dashboard.loyalty.rewards.toggle', $reward) }}" method="POST" class="d-inline">@csrf
                                    <button class="btn btn-sm btn-ghost-{{ $reward->is_active ? 'warning' : 'success' }} btn-icon" title="{{ $reward->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $reward->is_active ? 'Deactivate' : 'Activate' }}"><i class="ti ti-{{ $reward->is_active ? 'player-pause' : 'player-play' }}"></i></button>
                                </form>
                                <x-admin.delete :action="route('dashboard.loyalty.rewards.destroy', $reward)" :confirm="'Delete the reward '.$reward->title.'? Its redemption history is kept.'" />
                            @endcan
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$rewards" />
    @endif
</div>
@endsection
