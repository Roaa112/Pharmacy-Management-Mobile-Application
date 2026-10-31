{{-- Self-hosted, version-pinned assets (see public/assets/libs/README.md). --}}
<link rel="stylesheet" href="{{ asset('assets/libs/tabler-1.6.1/tabler.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/libs/tabler-icons-3.49.0/tabler-icons.min.css') }}">
<link rel="stylesheet" href="{{ asset('assets/admin/admin.css') }}?v={{ filemtime(public_path('assets/admin/admin.css')) }}">
