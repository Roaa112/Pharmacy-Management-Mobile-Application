@extends('admin.layout')

@section('title', 'Coupons')
@section('pretitle', 'Marketing')
@section('subtitle', 'Percentage codes customers enter at checkout. The discount applies to items plus delivery.')

@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCoupon"><i class="ti ti-plus me-1"></i>New coupon</button>
@endsection

@section('content')
<div class="card">
    @if ($coupons->isEmpty())
        <x-admin.empty icon="ticket-off" title="No coupons yet" />
    @else
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr><th>Code</th><th>Discount</th><th>State</th><th>Valid</th><th>Usage</th><th>Rules</th><th class="w-1"></th></tr></thead>
                <tbody>
                @foreach ($coupons as $coupon)
                    @php
                        $now = \App\Support\StoreClock::now();
                        $state = match (true) {
                            !$coupon->is_active => 'inactive',
                            $coupon->start_at && $coupon->start_at->gt($now) => 'scheduled',
                            $coupon->end_at && $coupon->end_at->lt($now) => 'expired',
                            $coupon->usage_limit && $coupon->used_count >= $coupon->usage_limit => 'used_up',
                            default => 'active',
                        };
                    @endphp
                    <tr>
                        <td><code class="fs-4">{{ $coupon->code }}</code></td>
                        <td class="fw-medium">{{ rtrim(rtrim($coupon->discount_value, '0'), '.') }}%</td>
                        <td><x-admin.status :status="$state" /></td>
                        <td class="small text-nowrap">
                            {{ $coupon->start_at?->format('M j, Y') ?? 'Any time' }}<br>
                            <span class="text-secondary">to {{ $coupon->end_at?->format('M j, Y') ?? 'no end' }}</span>
                        </td>
                        <td style="min-width: 9rem">
                            <div class="small mb-1">
                                {{ $coupon->used_count }}{{ $coupon->usage_limit ? ' / '.$coupon->usage_limit : '' }} uses · {{ $coupon->orders_count }} orders
                            </div>
                            @if ($coupon->usage_limit)
                                <div class="progress progress-sm"><div class="progress-bar bg-teal" style="width: {{ min(100, round($coupon->used_count / $coupon->usage_limit * 100)) }}%"></div></div>
                            @endif
                        </td>
                        <td class="small">{{ $coupon->once_per_user ? 'Once per customer' : 'Reusable' }}</td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <button class="btn btn-sm btn-ghost-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editCoupon{{ $coupon->id }}" title="Edit" aria-label="Edit"><i class="ti ti-edit"></i></button>
                                <x-admin.delete :action="route('dashboard.coupons.destroy', $coupon)" confirm="Delete this coupon? Orders that used it keep their totals." />
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <x-admin.pagination :paginator="$coupons" />
    @endif
</div>
@endsection

@push('modals')
    @foreach ([null, ...$coupons] as $coupon)
        @php $editing = $coupon !== null; @endphp
        <x-admin.modal :id="$editing ? 'editCoupon'.$coupon->id : 'createCoupon'" :title="$editing ? 'Edit coupon '.$coupon->code : 'New coupon'"
                       :action="$editing ? route('dashboard.coupons.update', $coupon->id) : route('dashboard.coupons.store')"
                       :method="$editing ? 'PUT' : 'POST'" :submit="$editing ? 'Save' : 'Create'">
            <div class="row">
                <x-admin.input col="col-md-6 mb-3" name="code" label="Code" :value="$coupon?->code" required maxlength="255" />
                <x-admin.input col="col-md-6 mb-3" type="number" name="discount_value" label="Discount (%)" :value="$coupon?->discount_value" min="1" max="100" step="0.01" required />
                <x-admin.input col="col-md-6 mb-3" type="date" name="start_at" label="Starts" :value="$coupon?->start_at?->toDateString()" required />
                <x-admin.input col="col-md-6 mb-3" type="date" name="end_at" label="Ends" :value="$coupon?->end_at?->toDateString()" required />
                <x-admin.input col="col-md-6 mb-3" type="number" name="usage_limit" label="Total uses allowed" :value="$coupon?->usage_limit" min="1" hint="Leave empty for unlimited." />
                <div class="col-md-6 mb-3">
                    <label class="form-label">Per customer</label>
                    <select name="once_per_user" class="form-select">
                        <option value="0" @selected(!$coupon?->once_per_user)>Can be reused</option>
                        <option value="1" @selected($coupon?->once_per_user)>Once per customer</option>
                    </select>
                </div>
            </div>
            <label class="form-check form-switch mb-0">
                <input type="hidden" name="is_active" value="0">
                <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($coupon?->is_active ?? true)>
                <span class="form-check-label">Active</span>
            </label>
        </x-admin.modal>
    @endforeach
@endpush
