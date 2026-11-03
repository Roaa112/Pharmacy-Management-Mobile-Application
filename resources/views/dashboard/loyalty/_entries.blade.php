{{-- A list of ledger entries. $entries (collection or paginator), $showCustomer (bool). --}}
@php
    $tone = ['earned' => 'green', 'bonus' => 'teal', 'adjusted' => 'purple', 'redeemed' => 'blue', 'expired' => 'secondary', 'reversed' => 'red', 'refunded' => 'orange', 'cancelled' => 'cyan'];
@endphp
@if (collect($entries instanceof \Illuminate\Contracts\Pagination\Paginator ? $entries->items() : $entries)->isEmpty())
    <x-admin.empty icon="star-off" title="No transactions" text="Points movements appear here as customers earn and use points." />
@else
    <div class="table-responsive">
        <table class="table table-vcenter card-table table-sm">
            <thead><tr>
                <th>Date</th>@if ($showCustomer ?? false)<th>Customer</th>@endif<th>Type</th><th class="text-end">Points</th><th>Details</th><th>Lot</th>
            </tr></thead>
            <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td class="small text-nowrap">{{ $entry->occurred_at?->format('M j, Y H:i') }}</td>
                    @if ($showCustomer ?? false)
                        <td>@if ($entry->user)<a href="{{ route('dashboard.customers.show', $entry->user_id) }}">{{ $entry->user->name }}</a>@else — @endif</td>
                    @endif
                    <td><span class="badge bg-{{ $tone[$entry->type] ?? 'secondary' }}-lt">{{ ucfirst($entry->type) }}</span></td>
                    <td class="text-end fw-medium {{ $entry->points < 0 ? 'text-red' : 'text-green' }}">{{ $entry->points > 0 ? '+' : '' }}{{ number_format($entry->points) }}</td>
                    <td class="small">
                        {{ $entry->description }}
                        @if ($entry->order_id) · <a href="{{ route('dashboard.orders.show', $entry->order_id) }}">order #{{ $entry->order_id }}</a>@endif
                        @if ($entry->admin) · <span class="text-secondary">by {{ $entry->admin->name }}</span>@endif
                        @if (($entry->metadata['shortfall'] ?? 0) > 0)<div class="text-orange">{{ $entry->metadata['shortfall'] }} points could not be taken back (already used): owed</div>@endif
                    </td>
                    <td class="small text-nowrap">
                        @if ($entry->remaining_points !== null)
                            <x-admin.status :status="$entry->status" /> {{ number_format($entry->remaining_points) }} left
                            <div class="text-secondary">{{ $entry->expires_at ? 'expires '.$entry->expires_at->format('M j, Y') : ($entry->status === 'pending' ? 'not usable yet' : 'never expires') }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
