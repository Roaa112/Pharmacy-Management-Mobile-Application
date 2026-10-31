@props(['icon' => 'database-off', 'title' => 'Nothing here yet', 'text' => null])

<div {{ $attributes->merge(['class' => 'empty-state text-center']) }}>
    <div class="empty-icon mb-2"><i class="ti ti-{{ $icon }}"></i></div>
    <p class="fw-medium mb-1">{{ $title }}</p>
    @if ($text)
        <p class="text-secondary small mb-0">{{ $text }}</p>
    @endif
    @if (trim($slot))
        <div class="mt-3">{{ $slot }}</div>
    @endif
</div>
