<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Dashboard') · {{ config('pharmacy.name') }} Admin</title>
    <script>
        // Restore the folded sidebar before first paint (Tabler stores the choice under this key).
        try { if ((localStorage.getItem('tabler-sidebar') || '').startsWith('folded')) document.documentElement.setAttribute('data-bs-sidebar', 'folded-hover'); } catch (e) {}
    </script>
    @include('admin.partials.assets')
    @stack('styles')
</head>
<body>
<div class="page">
    @include('admin.partials.sidebar')

    <div class="page-wrapper">
        @include('admin.partials.topbar')

        <div class="page-body mt-3">
            <div class="container-xl">
                <div class="page-header d-print-none">
                    <div class="row g-2 align-items-center">
                        <div class="col min-w-0">
                            @hasSection('pretitle')
                                <div class="page-pretitle">@yield('pretitle')</div>
                            @endif
                            <h2 class="page-title">@yield('title', 'Dashboard')</h2>
                            @hasSection('subtitle')
                                <div class="text-secondary mt-1">@yield('subtitle')</div>
                            @endif
                        </div>
                        @hasSection('actions')
                            <div class="col-auto ms-auto d-print-none">
                                <div class="btn-list">@yield('actions')</div>
                            </div>
                        @endif
                    </div>
                </div>

                @include('admin.partials.flash')

                @yield('content')
            </div>
        </div>

        <footer class="footer footer-transparent d-print-none">
            <div class="container-xl text-secondary small">
                {{ config('pharmacy.name') }} administration · {{ now()->year }}
            </div>
        </footer>
    </div>
</div>

@stack('modals')

<script src="{{ asset('assets/libs/tabler-1.6.1/tabler.min.js') }}"></script>
<script>
    // Ask for confirmation on any form marked data-confirm (delete, cancel, reject...).
    document.addEventListener('submit', function (event) {
        const message = event.target.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
</script>
@stack('scripts')
</body>
</html>
