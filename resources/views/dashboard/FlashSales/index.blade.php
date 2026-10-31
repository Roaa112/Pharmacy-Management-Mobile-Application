@extends('admin.layout')

@section('title', 'Flash sales')
@section('pretitle', 'Marketing')
@section('subtitle', 'Percentage discounts attached to products from the product form.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createFlashSale"><i class="ti ti-plus me-1"></i>New flash sale</button>
@endsection

@section('content')
<div class="alert alert-info">
    <div class="d-flex gap-2"><i class="ti ti-info-circle fs-2"></i>
        <div>A flash sale price applies only while the sale is <strong>active</strong> and inside its one-hour window, from the start time up to (not including) the end time, in {{ config('pharmacy.timezone') }} time. The app, cart and checkout all use this same rule.</div>
    </div>
</div>
<div class="card">
    @if ($flashSales->isEmpty())
        <x-admin.empty icon="bolt-off" title="No flash sales yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Discount</th><th>Status</th><th>Starts</th><th>Product</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($flashSales as $flashSale)
                    <tr>
                        <td class="fw-medium"><i class="ti ti-bolt text-yellow me-1"></i>{{ rtrim(rtrim($flashSale->discount_value, '0'), '.') }}%</td>
                        <td>
                            <x-admin.status :status="$flashSale->is_active ? 'active' : 'inactive'" />
                            @if ($flashSale->isLive())<span class="badge bg-green-lt ms-1">Live now</span>@elseif ($flashSale->is_active && $flashSale->ends_at->lte(\App\Support\StoreClock::now()))<span class="badge bg-secondary-lt ms-1">Ended</span>@endif
                        </td>
                        <td class="small text-nowrap">{{ $flashSale->starts_at->format('M j, Y · H:i') }}<br><span class="text-secondary">window ends {{ $flashSale->ends_at->format('H:i') }}</span></td>
                        <td>
                            @if ($flashSale->product)
                                <a href="{{ route('dashboard.products.edit', $flashSale->product->id) }}">{{ $flashSale->product->name_en }}</a>
                            @else
                                <span class="text-secondary small">Not attached</span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editFlashSale{{ $flashSale->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
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
        @php $editing = $flashSale !== null; @endphp
        <x-admin.modal :id="$editing ? 'editFlashSale'.$flashSale->id : 'createFlashSale'" :title="$editing ? 'Edit flash sale' : 'New flash sale'"
                       :action="$editing ? route('dashboard.flash_sales.update', $flashSale->id) : route('dashboard.flash_sales.store')"
                       :method="$editing ? 'PUT' : 'POST'" :submit="$editing ? 'Save' : 'Create'">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" type="number" name="discount_value" label="Discount (%)" :value="$flashSale?->discount_value" min="0.01" max="100" step="0.01" required />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-select" required>
                        <option value="1" @selected($flashSale?->is_active)>Active</option>
                        <option value="0" @selected($editing && !$flashSale->is_active)>Inactive</option>
                    </select>
                </div>
                <x-admin.input col="col-md-6 mb-3" type="date" name="date" label="Date" :value="$flashSale?->date" required />
                <x-admin.input col="col-md-6 mb-3" type="time" name="time" label="Start time" :value="$flashSale ? substr($flashSale->time, 0, 5) : null" required />
            </div>
        </x-admin.modal>
    @endforeach
@endpush
