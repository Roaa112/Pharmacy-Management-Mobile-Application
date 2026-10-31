@props(['amount', 'decimals' => 2])
<span {{ $attributes->merge(['class' => 'text-nowrap']) }}>{{ number_format((float) $amount, $decimals) }} <span class="text-secondary small">{{ config('pharmacy.currency') }}</span></span>
