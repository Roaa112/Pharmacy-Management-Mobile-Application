{{-- Customer › Loyalty: balances, the full points history with filters, adjustments and reward redemption. --}}
<div class="mt-4" id="loyalty">
    <h3 class="mb-3"><i class="ti ti-star me-1 text-yellow"></i>Loyalty points</h3>
    <div class="row row-cards mb-3">
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Valid now" icon="star" tone="yellow" :value="number_format($loyalty['available'])" :hint="$loyalty['next_expiry_at'] ? 'Next expiry '.\Carbon\Carbon::parse($loyalty['next_expiry_at'])->format('M j, Y') : null" /></div>
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Pending" icon="hourglass" tone="secondary" :value="number_format($loyalty['pending'])" hint="Not usable yet" /></div>
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Expiring soon" icon="clock-exclamation" tone="orange" :value="number_format($loyalty['expiring_soon'])" /></div>
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Total earned" icon="trending-up" tone="green" :value="number_format($loyalty['total_earned'])" /></div>
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Total redeemed" icon="gift" tone="blue" :value="number_format($loyalty['total_redeemed'])" :hint="$loyalty['total_returned'] ? number_format($loyalty['total_returned']).' given back' : null" /></div>
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Total expired" icon="clock-x" :value="number_format($loyalty['total_expired'])" /></div>
        <div class="col-6 col-md-3 col-xl"><x-admin.stat label="Total reversed" icon="receipt-refund" tone="red" :value="number_format($loyalty['total_reversed'])" :hint="$loyalty['owed'] ? number_format($loyalty['owed']).' owed' : null" /></div>
    </div>

    <div class="row row-cards">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header">
                    <form method="GET" action="{{ route('dashboard.customers.show', $customer) }}#loyalty" class="row g-2 w-100">
                        <div class="col-sm-4">
                            <select name="points_type" class="form-select" aria-label="Type"><option value="">All movements</option>
                                @foreach (\App\Models\LoyaltyLedgerEntry::TYPES as $type)<option value="{{ $type }}" @selected(request('points_type') === $type)>{{ ucfirst($type) }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-sm-3"><input type="date" name="points_from" value="{{ request('points_from') }}" class="form-control" aria-label="From"></div>
                        <div class="col-sm-3"><input type="date" name="points_to" value="{{ request('points_to') }}" class="form-control" aria-label="To"></div>
                        <div class="col-sm-2"><button class="btn btn-primary w-100">Filter</button></div>
                    </form>
                </div>
                @include('dashboard.loyalty._entries', ['entries' => $pointEntries, 'showCustomer' => false])
                <x-admin.pagination :paginator="$pointEntries" />
            </div>
        </div>
        <div class="col-xl-4">
            @can('loyalty.adjust_points')
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Adjust points</h3></div>
                    <form method="POST" action="{{ route('dashboard.loyalty.adjust', $customer) }}" class="card-body" data-confirm="Record this points adjustment?">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div class="row">
                            <x-admin.input col="col-6 mb-3" type="number" name="points" label="Points (+ / −)" required step="1" />
                            <div class="col-6 mb-3"><label class="form-label">Kind</label>
                                <select name="type" class="form-select"><option value="adjusted">Adjustment</option><option value="bonus">Bonus</option></select></div>
                        </div>
                        <x-admin.input name="reason" label="Reason" required minlength="3" maxlength="255" />
                        <x-admin.input type="number" name="valid_days" label="Valid for (days, added points)" min="1" max="3650" hint="Empty: the usual validity." />
                        <button class="btn btn-primary w-100">Save adjustment</button>
                        <small class="form-hint mt-2">Removing points takes the oldest valid ones first and never goes below zero.</small>
                    </form>
                </div>
            @endcan
            @can('loyalty.manage')
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Redeem a reward</h3></div>
                    <form method="POST" action="{{ route('dashboard.loyalty.redemptions.store', $customer) }}" class="card-body">
                        @csrf
                        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <div class="mb-3"><label class="form-label required">Reward</label>
                            <select name="reward_id" class="form-select" required>
                                @forelse ($rewards as $reward)
                                    <option value="{{ $reward->id }}" @disabled($reward->points_required > $loyalty['available'])>{{ $reward->title }} · {{ number_format($reward->points_required) }} pts</option>
                                @empty
                                    <option value="" disabled>No rewards available</option>
                                @endforelse
                            </select></div>
                        <x-admin.input type="number" name="quantity" label="Quantity" value="1" min="1" max="100" />
                        <label class="form-check mb-3"><input type="hidden" name="fulfil_now" value="0"><input type="checkbox" class="form-check-input" name="fulfil_now" value="1" checked><span class="form-check-label">Handed over now</span></label>
                        <button class="btn btn-success w-100" @disabled($rewards->isEmpty())>Redeem</button>
                    </form>
                </div>
            @endcan
            <div class="card">
                <div class="card-header"><h3 class="card-title">Redemptions</h3><div class="card-actions"><a href="{{ route('dashboard.loyalty.redemptions.index', ['search' => $customer->email]) }}">All</a></div></div>
                <div class="list-group list-group-flush">
                    @forelse ($redemptions as $redemption)
                        <div class="list-group-item small d-flex">
                            <span>{{ $redemption->number }} · {{ $redemption->title() }}<span class="d-block text-secondary">{{ $redemption->created_at->format('M j, Y') }} · {{ number_format($redemption->points) }} pts</span></span>
                            <x-admin.status class="ms-auto align-self-center" :status="$redemption->status" />
                        </div>
                    @empty
                        <div class="list-group-item text-secondary small">No redemptions yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
