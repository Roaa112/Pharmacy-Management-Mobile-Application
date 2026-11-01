@extends('admin.layout')

@php
    $targetName = fn ($target) => ($lookups[$target->target_type][$target->target_id] ?? null) ?? ucfirst($target->target_type).' #'.$target->target_id;
    $now = \App\Support\StoreClock::now();
@endphp

@section('title', $section ? $sections[$section][0] : 'Promotions')
@section('pretitle', 'Marketing')
@section('subtitle', 'Automatic discounts, gift offers and free delivery. Every price in the app, cart and checkout comes from these rules through one engine.')

@section('actions')
    <div class="dropdown">
        <button class="btn btn-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="ti ti-plus me-1"></i>New promotion</button>
        <div class="dropdown-menu dropdown-menu-end">
            @foreach (\App\Models\DiscountRule::TYPES as $type)
                <a class="dropdown-item" href="{{ route('dashboard.discounts.create', ['type' => $type]) }}">{{ \App\Models\DiscountRule::typeLabel($type) }}</a>
            @endforeach
        </div>
    </div>
@endsection

@section('content')
<div class="card mb-3">
    <div class="card-header">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item"><a class="nav-link @if (!$section) active @endif" href="{{ route('dashboard.discounts.index', request()->except(['section', 'page'])) }}">All</a></li>
            @foreach ($sections as $key => [$label, $types, $icon])
                <li class="nav-item"><a class="nav-link @if ($section === $key) active @endif" href="{{ route('dashboard.discounts.index', ['section' => $key] + request()->except(['section', 'page'])) }}"><i class="ti ti-{{ $icon }} me-1"></i>{{ $label }}</a></li>
            @endforeach
        </ul>
    </div>
    <form class="card-body d-flex flex-wrap gap-2" method="GET">
        @if ($section)<input type="hidden" name="section" value="{{ $section }}">@endif
        <input type="search" name="q" value="{{ request('q') }}" class="form-control w-auto" placeholder="Search name or #id" aria-label="Search">
        <select name="status" class="form-select w-auto" aria-label="Status">
            <option value="">Any status</option>
            @foreach (['active', 'scheduled', 'expired', 'inactive', 'archived'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <select name="campaign" class="form-select w-auto" aria-label="Campaign">
            <option value="">Any campaign</option>
            @foreach ($campaigns as $id => $name)
                <option value="{{ $id }}" @selected((int) request('campaign') === $id)>{{ $name }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline-primary">Filter</button>
    </form>
</div>

<div class="card">
    @if ($discounts->isEmpty())
        <x-admin.empty icon="discount-off" title="No promotions match" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Promotion</th><th>Applies to</th><th>Rule</th><th>Precedence</th><th>State</th><th>Valid</th><th>Uses</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($discounts as $discount)
                    @php $state = $discount->trashed() ? 'archived' : $discount->state($now); @endphp
                    <tr>
                        <td>
                            <a href="{{ route('dashboard.discounts.show', $discount->id) }}" class="fw-medium">{{ $discount->name }}</a>
                            <div class="small text-secondary">{{ \App\Models\DiscountRule::typeLabel($discount->discount_type) }} · #{{ $discount->id }}@if ($discount->campaign) · {{ $discount->campaign->name_en }}@endif</div>
                        </td>
                        <td style="max-width: 18rem">
                            @forelse ($discount->targets->where('is_gift', false) as $target)
                                <span class="badge {{ $target->is_excluded ? 'bg-red-lt' : 'bg-blue-lt' }} me-1 mb-1">{{ $target->is_excluded ? 'Not ' : '' }}{{ ucfirst($target->target_type) }}: {{ $targetName($target) }}</span>
                            @empty
                                <span class="text-secondary small">All products</span>
                            @endforelse
                        </td>
                        <td class="small">@include('dashboard.Discounts.partials.rule', ['promotion' => $discount])</td>
                        <td class="small text-nowrap">
                            Priority {{ $discount->priority }}
                            @if ($discount->is_exclusive)<span class="badge bg-purple-lt">exclusive</span>@endif
                            @if ($discount->is_stackable)<span class="badge bg-teal-lt">stackable</span>@endif
                        </td>
                        <td><x-admin.status :status="$state" /></td>
                        <td class="small text-nowrap">{{ $discount->startsAt()?->format('M j, Y H:i') ?? '—' }}<br><span class="text-secondary">to {{ $discount->endsAt()?->format('M j, Y H:i') ?? '—' }}</span></td>
                        <td class="small">{{ $discount->uses_count }}{{ $discount->usage_limit ? ' / '.$discount->usage_limit : '' }}</td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <a class="btn btn-sm btn-ghost-primary btn-icon" href="{{ route('dashboard.discounts.show', $discount->id) }}" title="View" aria-label="View"><i class="ti ti-eye"></i></a>
                                @unless ($discount->trashed())
                                    <a class="btn btn-sm btn-ghost-primary btn-icon" href="{{ route('dashboard.discounts.edit', $discount->id) }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></a>
                                    <form method="POST" action="{{ route('dashboard.discounts.toggle', $discount->id) }}">@csrf
                                        <button class="btn btn-sm btn-ghost-{{ $discount->is_active ? 'warning' : 'success' }} btn-icon" title="{{ $discount->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $discount->is_active ? 'Deactivate' : 'Activate' }}"><i class="ti ti-{{ $discount->is_active ? 'player-pause' : 'player-play' }}"></i></button>
                                    </form>
                                    <form method="POST" action="{{ route('dashboard.discounts.duplicate', $discount->id) }}">@csrf
                                        <button class="btn btn-sm btn-ghost-secondary btn-icon" title="Duplicate" aria-label="Duplicate"><i class="ti ti-copy"></i></button>
                                    </form>
                                    <x-admin.delete :action="route('dashboard.discounts.destroy', $discount->id)" confirm="Archive this promotion? It stops applying; orders that used it keep their snapshot." />
                                @endunless
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$discounts" />
    @endif
</div>
@endsection
