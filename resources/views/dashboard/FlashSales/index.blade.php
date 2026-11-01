@extends('admin.layout')

@section('title', 'Flash sales')
@section('pretitle', 'Marketing')
@section('subtitle', 'Time-boxed prices for chosen products or variants, with optional unit limits.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createFlashSale"><i class="ti ti-plus me-1"></i>New flash sale</button>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('assets/libs/tom-select-2.6.2/tom-select.bootstrap5.min.css') }}">
@endpush

@section('content')
<div class="alert alert-info">
    <div class="d-flex gap-2"><i class="ti ti-info-circle fs-2"></i>
        <div>A flash sale price applies only while the sale is <strong>active</strong> and inside its window, from the start time up to (not including) the end time, in {{ config('pharmacy.timezone') }} time. While live it wins over regular discounts, and a coupon applies on top only if the coupon is set to combine with promotions. A cart line that would exceed a unit limit gets the regular price. Two active sales cannot cover the same product at the same time.</div>
    </div>
</div>
<div class="card">
    @if ($flashSales->isEmpty())
        <x-admin.empty icon="bolt-off" title="No flash sales yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Sale</th><th>Discount</th><th>Status</th><th>Window</th><th>Products</th><th>Limits</th><th>Sold</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($flashSales as $flashSale)
                    <tr>
                        <td class="fw-medium">{{ $flashSale->name }}</td>
                        <td class="fw-medium"><i class="ti ti-bolt text-yellow me-1"></i>{{ rtrim(rtrim($flashSale->discount_value, '0'), '.') }}{{ $flashSale->discount_type === 'fixed' ? ' '.config('pharmacy.currency').' off' : '%' }}</td>
                        <td>
                            <x-admin.status :status="$flashSale->state()" />
                            @if ($flashSale->isLive())<span class="badge bg-green-lt ms-1">Live now</span>@endif
                        </td>
                        <td class="small text-nowrap">{{ $flashSale->starts_at->format('M j, Y · H:i') }}<br><span class="text-secondary">to {{ $flashSale->ends_at->format('M j, Y · H:i') }}</span></td>
                        <td class="small" style="max-width: 16rem">
                            @forelse ($flashSale->items as $item)
                                <span class="badge bg-blue-lt me-1 mb-1">{{ $item->product?->name_en ?? '#'.$item->product_id }}{{ $item->size ? ' — '.$item->size->size : '' }}</span>
                            @empty
                                <span class="text-secondary">Not attached</span>
                            @endforelse
                        </td>
                        <td class="small">{{ $flashSale->quantity_limit ? $flashSale->quantity_limit.' units' : 'No unit limit' }}<br>{{ $flashSale->per_customer_limit ? $flashSale->per_customer_limit.' per customer' : '' }}</td>
                        <td class="small">{{ (int) ($sold[$flashSale->id]->units ?? 0) }} units<br><span class="text-secondary"><x-admin.money :amount="$sold[$flashSale->id]->discount ?? 0" /> off</span></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editFlashSale{{ $flashSale->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <form method="POST" action="{{ route('dashboard.flash_sales.toggle', $flashSale->id) }}">@csrf<button class="btn btn-sm btn-ghost-{{ $flashSale->is_active ? 'warning' : 'success' }} btn-icon" title="{{ $flashSale->is_active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $flashSale->is_active ? 'Deactivate' : 'Activate' }}"><i class="ti ti-{{ $flashSale->is_active ? 'player-pause' : 'player-play' }}"></i></button></form>
                                <x-admin.delete :action="route('dashboard.flash_sales.destroy', $flashSale)" />
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('modals')
    @foreach ([null, ...$flashSales] as $flashSale)
        @php
            $editing = $flashSale !== null;
            $pickedProducts = $flashSale ? $flashSale->items->whereNull('product_size_id')->pluck('product_id')->all() : [];
            $pickedSizes = $flashSale ? $flashSale->items->whereNotNull('product_size_id')->pluck('product_size_id')->all() : [];
        @endphp
        <x-admin.modal :id="$editing ? 'editFlashSale'.$flashSale->id : 'createFlashSale'" :title="$editing ? 'Edit flash sale' : 'New flash sale'"
                       :action="$editing ? route('dashboard.flash_sales.update', $flashSale->id) : route('dashboard.flash_sales.store')"
                       :method="$editing ? 'PUT' : 'POST'" :submit="$editing ? 'Save' : 'Create'" size="lg">
            <input type="hidden" name="sync_items" value="1">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="name_en" label="Name (English)" :value="$flashSale?->name_en" maxlength="255" />
                <x-admin.input col="col-md-6 mb-3" name="name_ar" label="Name (Arabic)" :value="$flashSale?->name_ar" maxlength="255" dir="rtl" />
                <div class="col-md-4 mb-3">
                    <label class="form-label">Discount type</label>
                    <select name="discount_type" class="form-select">
                        <option value="percent" @selected(($flashSale?->discount_type ?? 'percent') === 'percent')>Percentage</option>
                        <option value="fixed" @selected($flashSale?->discount_type === 'fixed')>Fixed amount per unit</option>
                    </select>
                </div>
                <x-admin.input col="col-md-4 mb-3" type="number" name="discount_value" label="Discount" :value="$flashSale?->discount_value" min="0.01" step="0.01" required hint="Percentage: at most 100." />
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-select" required>
                        <option value="1" @selected($flashSale?->is_active)>Active</option>
                        <option value="0" @selected($editing && !$flashSale->is_active)>Inactive</option>
                    </select>
                </div>
                <x-admin.input col="col-md-4 mb-3" type="date" name="date" label="Start date" :value="$flashSale?->date" required />
                <x-admin.input col="col-md-4 mb-3" type="time" name="time" label="Start time" :value="$flashSale ? substr($flashSale->time, 0, 5) : null" required />
                <x-admin.input col="col-md-4 mb-3" type="datetime-local" name="ends_at" label="Ends" :value="$flashSale?->getRawOriginal('ends_at') ? \Illuminate\Support\Carbon::parse($flashSale->getRawOriginal('ends_at'))->format('Y-m-d\TH:i') : null" :required="!$editing" />
                <x-admin.input col="col-md-4 mb-3" type="number" name="quantity_limit" label="Units at this price (all customers)" :value="$flashSale?->quantity_limit" min="1" hint="Empty = no limit." />
                <x-admin.input col="col-md-4 mb-3" type="number" name="per_customer_limit" label="Units per customer" :value="$flashSale?->per_customer_limit" min="1" />
                <x-admin.input col="col-md-4 mb-3" type="number" name="priority" label="Priority" :value="$flashSale?->priority ?? 0" min="-1000" max="1000" />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Products (every size)</label>
                    <select name="product_ids[]" class="form-select flash-picker" multiple>
                        @foreach ($products as $id => $name)<option value="{{ $id }}" @selected(in_array($id, $pickedProducts))>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Single variants</label>
                    <select name="size_ids[]" class="form-select flash-picker" multiple>
                        @foreach ($sizes as $s)<option value="{{ $s->id }}" @selected(in_array($s->id, $pickedSizes))>{{ $s->product?->name_en }} — {{ $s->size }} ({{ (int) $s->stock }})</option>@endforeach
                    </select>
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endpush

@push('scripts')
    <script src="{{ asset('assets/libs/tom-select-2.6.2/tom-select.complete.min.js') }}"></script>
    <script>document.querySelectorAll('.flash-picker').forEach(s => new TomSelect(s, { maxOptions: null, dropdownParent: 'body', plugins: ['remove_button'] }));</script>
@endpush
