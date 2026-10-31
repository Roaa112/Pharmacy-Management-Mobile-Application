{{--
    A modal whose content is a form. Usage:
    <x-admin.modal id="createCoupon" title="New coupon" :action="route('dashboard.coupons.store')">
        ...fields...
    </x-admin.modal>
--}}
@props([
    'id',
    'title',
    'action',
    'method' => 'POST',
    'size' => null,
    'files' => false,
    'submit' => 'Save',
    'submitClass' => 'btn-primary',
])

<div class="modal modal-blur fade" id="{{ $id }}" tabindex="-1" aria-labelledby="{{ $id }}-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable {{ $size ? 'modal-'.$size : '' }}">
        <form class="modal-content" action="{{ $action }}" method="POST" @if ($files) enctype="multipart/form-data" @endif>
            @csrf
            @unless (strtoupper($method) === 'POST')
                @method($method)
            @endunless
            <div class="modal-header">
                <h5 class="modal-title" id="{{ $id }}-title">{{ $title }}</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                {{ $slot }}
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn {{ $submitClass }}">{{ $submit }}</button>
            </div>
        </form>
    </div>
</div>
