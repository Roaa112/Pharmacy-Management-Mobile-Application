@extends('admin.layout')

@section('title', 'Loyalty settings')
@section('pretitle', 'Loyalty')
@section('subtitle', 'Rules for earning, expiry and redemption. Changes apply to points earned and redeemed from now on; existing points keep their dates.')

@php
    $switch = function (string $name, string $label, ?string $hint = null) use ($s) {
        $checked = old($name, $s->{$name}) ? 'checked' : '';
        $hintHtml = $hint ? '<small class="form-hint">'.e($hint).'</small>' : '';
        return '<div class="mb-3"><label class="form-check form-switch mb-0"><input type="hidden" name="'.$name.'" value="0">'
            .'<input class="form-check-input" type="checkbox" name="'.$name.'" value="1" '.$checked.'>'
            .'<span class="form-check-label">'.e($label).'</span></label>'.$hintHtml.'</div>';
    };
    $currency = config('pharmacy.currency');
    $neverExpires = (bool) old('points_never_expire', $s->points_never_expire);
@endphp

@section('content')
@include('dashboard.loyalty._tabs')

<form method="POST" action="{{ route('dashboard.loyalty.settings.update') }}">
    @csrf @method('PUT')
    <div class="row row-cards">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-trending-up me-2 text-secondary"></i>Earning</h3></div>
                <div class="card-body">
                    {!! $switch('loyalty_enabled', 'Loyalty programme active', 'Off: orders earn nothing and nothing can be redeemed. Existing points are kept.') !!}
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" type="number" name="get_y" label="Points" :value="old('get_y', $s->get_y)" min="0" step="1" required />
                        <x-admin.input col="col-md-6 mb-3" type="number" name="spend_x" :label="'Per amount spent ('.$currency.')'" :value="old('spend_x', $s->spend_x)" min="0.01" step="0.01" required />
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Points are earned on</label>
                        <select name="loyalty_earn_basis" class="form-select">
                            <option value="subtotal" @selected(old('loyalty_earn_basis', $s->loyalty_earn_basis) === 'subtotal')>Products subtotal</option>
                            <option value="paid" @selected(old('loyalty_earn_basis', $s->loyalty_earn_basis) === 'paid')>Final amount paid</option>
                        </select>
                    </div>
                    {!! $switch('loyalty_earn_on_shipping', 'Delivery fee earns points') !!}
                    {!! $switch('loyalty_discounts_reduce_points', 'Discounts reduce the points (products subtotal basis)', 'Coupon, promotion and points discounts are taken off the amount points are earned on.') !!}
                    {!! $switch('loyalty_earn_on_cod', 'Cash-on-delivery orders earn points') !!}
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" type="number" name="loyalty_min_order_amount" :label="'Minimum order ('.$currency.')'" :value="old('loyalty_min_order_amount', $s->loyalty_min_order_amount)" min="0" step="0.01" hint="Smaller orders earn nothing." />
                        <x-admin.input col="col-md-6 mb-3" type="number" name="loyalty_max_points_per_order" label="Maximum points per order" :value="old('loyalty_max_points_per_order', $s->loyalty_max_points_per_order)" min="1" hint="Empty: no limit." />
                    </div>
                    <div class="mb-0">
                        <label class="form-label required">Points become usable</label>
                        <select name="loyalty_activation" class="form-select">
                            <option value="immediate" @selected(old('loyalty_activation', $s->loyalty_activation) === 'immediate')>Immediately at checkout</option>
                            <option value="paid" @selected(old('loyalty_activation', $s->loyalty_activation) === 'paid')>Once the order is paid</option>
                            <option value="completed" @selected(old('loyalty_activation', $s->loyalty_activation) === 'completed')>Once the order is completed / delivered</option>
                        </select>
                        <small class="form-hint">Until then they show as pending. Canceled orders never make points usable.</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-clock me-2 text-secondary"></i>Expiry</h3></div>
                <div class="card-body">
                    {!! $switch('points_never_expire', 'Points never expire') !!}
                    <div class="mb-3">
                        <label class="form-label">Points are valid for (days after they become usable)</label>
                        <div class="input-group">
                            <input type="number" name="points_validity_days" id="validity-days" class="form-control" min="0" max="3650" value="{{ old('points_validity_days', $s->points_validity_days) }}" @disabled($neverExpires)>
                            @foreach (\App\Modules\Points\Services\LoyaltySettings::EXPIRY_PRESETS as $preset)
                                <button type="button" class="btn btn-outline-secondary" data-days="{{ $preset }}">{{ $preset }}</button>
                            @endforeach
                        </div>
                        <small class="form-hint">0 = points lapse the day they become usable.</small>
                    </div>
                    <x-admin.input type="number" name="loyalty_expiry_warning_days" label="Show as expiring soon within (days)" :value="old('loyalty_expiry_warning_days', $s->loyalty_expiry_warning_days)" min="1" max="365" required />
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h3 class="card-title"><i class="ti ti-gift me-2 text-secondary"></i>Redemption</h3></div>
                <div class="card-body">
                    <x-admin.input type="number" name="loyalty_point_value" :label="'Value of one point at checkout ('.$currency.')'" :value="old('loyalty_point_value', $s->loyalty_point_value)" min="0" step="0.0001" hint="0 = points cannot pay at checkout (rewards still work). Points never pay for delivery." />
                    <div class="row">
                        <x-admin.input col="col-md-6 mb-3" type="number" name="loyalty_min_redeem_points" label="Minimum points per redemption" :value="old('loyalty_min_redeem_points', $s->loyalty_min_redeem_points)" min="0" />
                        <x-admin.input col="col-md-6 mb-3" type="number" name="loyalty_max_redeem_points_per_order" label="Maximum points per order" :value="old('loyalty_max_redeem_points_per_order', $s->loyalty_max_redeem_points_per_order)" min="1" hint="Empty: no limit." />
                    </div>
                    {!! $switch('loyalty_combine_with_discounts', 'Points can be combined with other discounts') !!}
                    {!! $switch('loyalty_allow_partial_redemption', 'Customers choose how many points to use', 'Off: an order uses all the points it can (up to the limits).') !!}
                    {!! $switch('loyalty_allow_multiple_redemptions', 'Several redemptions can be open at once', 'Off: a new redemption waits until the previous one is handed over or its order is finished.') !!}
                    <x-admin.input type="number" name="loyalty_redemption_validity_days" label="Rewards must be collected within (days)" :value="old('loyalty_redemption_validity_days', $s->loyalty_redemption_validity_days)" min="1" max="3650" hint="Not collected in time: the redemption expires and the points and stock go back. Empty: never." />
                </div>
            </div>
        </div>
    </div>
    <div class="mt-3"><button class="btn btn-primary"><i class="ti ti-device-floppy me-1"></i>Save loyalty settings</button></div>
</form>
@endsection

@push('scripts')
<script>
(function () {
    const input = document.getElementById('validity-days');
    document.querySelectorAll('[data-days]').forEach(b => b.addEventListener('click', () => { if (!input.disabled) input.value = b.dataset.days; }));
    const never = document.querySelector('input[type=checkbox][name=points_never_expire]');
    never && never.addEventListener('change', () => { input.disabled = never.checked; });
})();
</script>
@endpush
