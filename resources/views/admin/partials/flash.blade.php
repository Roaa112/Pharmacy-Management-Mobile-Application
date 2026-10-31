@if (session('success'))
    <div class="alert alert-success alert-dismissible" role="alert">
        <div class="d-flex gap-2"><i class="ti ti-circle-check fs-2"></i><div>{{ session('success') }}</div></div>
        <a class="btn-close" data-bs-dismiss="alert" aria-label="Close"></a>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger alert-dismissible" role="alert">
        <div class="d-flex gap-2"><i class="ti ti-alert-circle fs-2"></i><div>{{ session('error') }}</div></div>
        <a class="btn-close" data-bs-dismiss="alert" aria-label="Close"></a>
    </div>
@endif

@if ($errors->any())
    <div class="alert alert-danger alert-dismissible" role="alert">
        <div class="d-flex gap-2">
            <i class="ti ti-alert-triangle fs-2"></i>
            <div>
                <h4 class="alert-title mb-1">Please fix the following</h4>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
        <a class="btn-close" data-bs-dismiss="alert" aria-label="Close"></a>
    </div>
@endif
