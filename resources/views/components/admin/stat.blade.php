@props(['label', 'value', 'icon' => 'chart-bar', 'tone' => 'primary', 'hint' => null, 'href' => null])

<div {{ $attributes->merge(['class' => 'card stat-card h-100']) }}>
    <div class="card-body">
        <div class="d-flex align-items-start justify-content-between">
            <div class="min-w-0">
                <div class="text-secondary small fw-medium text-uppercase">{{ $label }}</div>
                <div class="stat-value mt-1">{{ $value }}</div>
                @if ($hint)
                    <div class="text-secondary small mt-1">{{ $hint }}</div>
                @endif
            </div>
            <span class="stat-icon bg-{{ $tone }}-lt"><i class="ti ti-{{ $icon }}"></i></span>
        </div>
        @if ($href)
            <a href="{{ $href }}" class="stretched-link" aria-label="{{ $label }}"></a>
        @endif
    </div>
</div>
