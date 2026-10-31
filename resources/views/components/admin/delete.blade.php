@props(['action', 'confirm' => 'Delete this item? This cannot be undone.', 'label' => null])

<form action="{{ $action }}" method="POST" class="d-inline" data-confirm="{{ $confirm }}">
    @csrf
    @method('DELETE')
    <button type="submit" {{ $attributes->merge(['class' => 'btn btn-sm btn-ghost-danger'.($label ? '' : ' btn-icon')]) }} title="Delete" aria-label="Delete">
        <i class="ti ti-trash"></i>@if ($label)<span class="ms-1">{{ $label }}</span>@endif
    </button>
</form>
