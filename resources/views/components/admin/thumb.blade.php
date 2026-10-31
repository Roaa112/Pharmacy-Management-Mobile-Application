@props(['src' => null, 'alt' => '', 'size' => null])

@if ($src)
    <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" {{ $attributes->merge(['class' => 'thumb'.($size ? ' thumb-'.$size : '')]) }}>
@else
    <span {{ $attributes->merge(['class' => 'thumb d-inline-flex align-items-center justify-content-center text-secondary'.($size ? ' thumb-'.$size : '')]) }}><i class="ti ti-photo-off"></i></span>
@endif
