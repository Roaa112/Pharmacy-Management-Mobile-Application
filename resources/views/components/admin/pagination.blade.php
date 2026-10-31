@props(['paginator'])

@if ($paginator->hasPages())
    <div {{ $attributes->merge(['class' => 'card-footer']) }}>{{ $paginator->links() }}</div>
@endif
