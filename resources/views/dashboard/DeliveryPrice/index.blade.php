@extends('admin.layout')

@section('title', 'Delivery prices')
@section('pretitle', 'Operations')
@section('subtitle', 'Delivery fee per governorate. Checkout matches the customer address city against these names.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createDeliveryPrice"><i class="ti ti-plus me-1"></i>New delivery price</button>
@endsection

@section('content')
<div class="card">
    @if ($deliveryPrice->isEmpty())
        <x-admin.empty icon="truck-off" title="No delivery prices yet" text="Without one, customers cannot check out to an address in that governorate." />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Governorate</th><th class="text-end">Fee</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($deliveryPrice as $delivery)
                    <tr>
                        <td class="fw-medium">{{ $delivery->governorate }}</td>
                        <td class="text-end"><x-admin.money :amount="$delivery->price" /></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editDeliveryPrice{{ $delivery->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.delivery_prices.destroy', $delivery)" />
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
    @foreach ([null, ...$deliveryPrice] as $delivery)
        @php $editing = $delivery !== null; @endphp
        <x-admin.modal :id="$editing ? 'editDeliveryPrice'.$delivery->id : 'createDeliveryPrice'" :title="$editing ? 'Edit delivery price' : 'New delivery price'"
                       :action="$editing ? route('dashboard.delivery_prices.update', $delivery->id) : route('dashboard.delivery_prices.store')"
                       :method="$editing ? 'PUT' : 'POST'" :submit="$editing ? 'Save' : 'Create'">
            <x-admin.input name="governorate" label="Governorate" :value="$delivery?->governorate" required />
            <x-admin.input type="number" name="price" :label="'Fee ('.config('pharmacy.currency').')'" :value="$delivery?->price" step="0.01" min="0" required />
        </x-admin.modal>
    @endforeach
@endpush
