{{-- Text-like form field. Create forms (no value) repopulate from old input; edit forms show the model value. --}}
@props(['name', 'label', 'type' => 'text', 'value' => null, 'hint' => null, 'col' => null])

@php
    $id = $attributes->get('id', str_replace(['[', ']', '.'], '_', $name).'_'.\Illuminate\Support\Str::random(4));
    $current = $value ?? old(str_replace(['[', ']'], ['.', ''], $name));
@endphp

<div class="{{ $col ?? 'mb-3' }}">
    <label class="form-label {{ $attributes->has('required') ? 'required' : '' }}" for="{{ $id }}">{{ $label }}</label>
    @if ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->except('id')->merge(['class' => 'form-control', 'rows' => 3]) }}>{{ $current }}</textarea>
    @else
        <input id="{{ $id }}" type="{{ $type }}" name="{{ $name }}" @if ($type !== 'file') value="{{ $current }}" @endif
               {{ $attributes->except('id')->merge(['class' => 'form-control']) }}>
    @endif
    @if ($hint)
        <small class="form-hint">{{ $hint }}</small>
    @endif
</div>
