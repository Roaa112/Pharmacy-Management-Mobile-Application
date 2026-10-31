@extends('admin.layout')

@php
    $types = [
        'percent' => ['Percentage off', 'percentage'],
        'fixed' => ['Fixed amount off', 'currency-dollar'],
        'buy_x_get_y' => ['Buy X, get a gift', 'gift'],
        'amount_gift' => ['Spend X, get a gift', 'gift-card'],
    ];
    $lookups = ['product' => $products, 'brand' => $brands, 'category' => $categories];
    $targetName = fn ($target) => ($lookups[$target->target_type][$target->target_id] ?? null) ?? ucfirst($target->target_type).' #'.$target->target_id;
    $grouped = $discounts->groupBy('discount_type');
@endphp

@section('title', 'Discounts')
@section('pretitle', 'Marketing')
@section('subtitle', 'Automatic price rules and gift offers for products, brands or categories.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDiscount"><i class="ti ti-plus me-1"></i>New discount</button>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/libs/tom-select-2.6.2/tom-select.bootstrap5.min.css') }}">
@endpush

@section('content')
@if ($discounts->isEmpty())
    <div class="card"><x-admin.empty icon="discount-off" title="No discounts yet" /></div>
@endif

@foreach ($types as $type => [$title, $icon])
    @continue(empty($grouped[$type]))
    <div class="card mb-3">
        <div class="card-header"><h3 class="card-title"><i class="ti ti-{{ $icon }} me-2 text-secondary"></i>{{ $title }}</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Applies to</th><th>Rule</th><th>Gift</th><th>State</th><th>Valid</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($grouped[$type] as $discount)
                    @php
                        $starts = $discount->starts_at ? \Carbon\Carbon::parse($discount->starts_at) : null;
                        $ends = $discount->ends_at ? \Carbon\Carbon::parse($discount->ends_at) : null;
                        $now = \App\Support\StoreClock::now();
                        $state = !$starts || !$ends ? 'inactive' : ($starts->gt($now) ? 'scheduled' : ($ends->lt($now) ? 'expired' : 'active'));
                        $gift = $discount->targets->firstWhere('is_gift', true);
                    @endphp
                    <tr>
                        <td>
                            @foreach ($discount->targets->where('is_gift', false) as $target)
                                <span class="badge bg-blue-lt me-1 mb-1">{{ ucfirst($target->target_type) }}: {{ $targetName($target) }}</span>
                            @endforeach
                        </td>
                        <td class="text-nowrap">
                            @switch($type)
                                @case('percent') {{ rtrim(rtrim($discount->discount_value, '0'), '.') }}% off @break
                                @case('fixed') <x-admin.money :amount="$discount->discount_value" /> off @break
                                @case('buy_x_get_y') Buy {{ $discount->min_quantity }}{{ $discount->free_quantity ? ', '.$discount->free_quantity.' free' : '' }} @break
                                @case('amount_gift') Spend <x-admin.money :amount="$discount->min_amount" /> @break
                            @endswitch
                        </td>
                        <td>{{ $gift ? ucfirst($gift->target_type).': '.$targetName($gift) : '—' }}</td>
                        <td><x-admin.status :status="$state" /></td>
                        <td class="small text-nowrap">{{ $starts?->format('M j, Y H:i') ?? '—' }}<br><span class="text-secondary">to {{ $ends?->format('M j, Y H:i') ?? '—' }}</span></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editDiscount{{ $discount->id }}" title="Change dates" aria-label="Change dates"><i class="ti ti-calendar-time"></i></button>
                                <x-admin.delete :action="route('dashboard.discounts.destroy', $discount->id)" />
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endforeach
@endsection

@push('modals')
    @foreach ($discounts as $discount)
        <x-admin.modal :id="'editDiscount'.$discount->id" title="Change validity" :action="route('dashboard.discounts.update', $discount->id)" method="PUT">
            <x-admin.input type="datetime-local" name="starts_at" label="Starts" :value="$discount->starts_at ? \Carbon\Carbon::parse($discount->starts_at)->format('Y-m-d\TH:i') : ''" required />
            <x-admin.input type="datetime-local" name="ends_at" label="Ends" :value="$discount->ends_at ? \Carbon\Carbon::parse($discount->ends_at)->format('Y-m-d\TH:i') : ''" required />
        </x-admin.modal>
    @endforeach

    <x-admin.modal id="createDiscount" title="New discount" :action="route('dashboard.discounts.store')" size="lg" submit="Create discount">
        <div class="mb-3">
            <label class="form-label required" for="discount_type">Type</label>
            <select id="discount_type" name="discount_type" class="form-select" required>
                @foreach ($types as $type => [$title])
                    <option value="{{ $type }}" @selected(old('discount_type') === $type)>{{ $title }}</option>
                @endforeach
            </select>
        </div>

        <div class="row" data-for="fixed percent">
            <x-admin.input col="col-md-6 mb-3" type="number" name="discount_value" label="Discount value" step="0.01" min="0" hint="Percent (0–100) or amount, depending on the type." />
        </div>
        <div class="row" data-for="buy_x_get_y">
            <x-admin.input col="col-md-6 mb-3" type="number" name="min_quantity" label="Minimum quantity" min="1" />
            <x-admin.input col="col-md-6 mb-3" type="number" name="free_quantity" label="Free quantity" min="0" />
        </div>
        <div class="row" data-for="amount_gift">
            <x-admin.input col="col-md-6 mb-3" type="number" name="min_amount" label="Minimum spend" step="0.01" min="0" />
        </div>

        @foreach (['targets' => 'Applies to', 'gift_targets' => 'Gift'] as $field => $label)
            <div class="mb-3" @if ($field === 'gift_targets') data-for="buy_x_get_y amount_gift" @endif>
                <label class="form-label required">{{ $label }}</label>
                <div class="row g-2 target-picker" data-field="{{ $field }}">
                    <div class="col-md-4">
                        <select name="{{ $field }}[0][type]" class="form-select target-type" aria-label="{{ $label }} type">
                            <option value="product">Product</option>
                            <option value="brand">Brand</option>
                            <option value="category">Category</option>
                        </select>
                    </div>
                    @foreach ($lookups as $kind => $options)
                        <div class="col-md-8 target-options" data-kind="{{ $kind }}">
                            <select class="form-select" data-name="{{ $field }}[0][id]" placeholder="Search {{ $kind }}…" aria-label="{{ ucfirst($kind) }}">
                                <option value="">Choose a {{ $kind }}</option>
                                @foreach ($options as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                    @if ($field === 'gift_targets')
                        <input type="hidden" name="gift_targets[0][is_gift]" value="1">
                    @endif
                </div>
            </div>
        @endforeach

        <div class="row">
            <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="starts_at" label="Starts" required />
            <x-admin.input col="col-md-6 mb-3" type="datetime-local" name="ends_at" label="Ends" required />
        </div>
    </x-admin.modal>
@endpush

@push('scripts')
    <script src="{{ asset('assets/libs/tom-select-2.6.2/tom-select.complete.min.js') }}"></script>
    <script>
        (function () {
            const modal = document.getElementById('createDiscount');
            const type = modal.querySelector('#discount_type');

            // Only the visible section's inputs are enabled, so hidden ones are not submitted.
            function toggleSections() {
                modal.querySelectorAll('[data-for]').forEach(section => {
                    const on = section.dataset.for.split(' ').includes(type.value);
                    section.classList.toggle('d-none', !on);
                    section.querySelectorAll('input, select').forEach(el => el.disabled = !on);
                    if (on) section.querySelectorAll('.target-picker').forEach(syncPicker);
                });
            }

            // One searchable select per kind; only the chosen kind carries the field name.
            function syncPicker(picker) {
                const kind = picker.querySelector('.target-type').value;
                picker.querySelectorAll('.target-options').forEach(wrapper => {
                    const select = wrapper.querySelector('select');
                    const active = wrapper.dataset.kind === kind;
                    wrapper.classList.toggle('d-none', !active);
                    select.name = active ? select.dataset.name : '';
                    if (select.tomselect) active ? select.tomselect.enable() : select.tomselect.disable();
                });
            }

            modal.querySelectorAll('.target-picker').forEach(picker => {
                picker.querySelectorAll('.target-options select').forEach(select => new TomSelect(select, { maxOptions: null, dropdownParent: 'body' }));
                picker.querySelector('.target-type').addEventListener('change', () => syncPicker(picker));
                syncPicker(picker);
            });

            type.addEventListener('change', toggleSections);
            toggleSections();
        })();
    </script>
@endpush
