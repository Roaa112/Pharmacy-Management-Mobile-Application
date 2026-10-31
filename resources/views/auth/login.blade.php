<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · {{ config('pharmacy.name') }} Admin</title>
    @include('admin.partials.assets')
</head>
<body class="d-flex flex-column">
<div class="page page-center">
    <div class="container container-tight py-4">
        <div class="text-center mb-4">
            <span class="d-inline-flex align-items-center gap-2 fs-2 fw-bold text-body">
                <span class="d-inline-flex align-items-center justify-content-center rounded-2 text-white"
                      style="width:2.5rem;height:2.5rem;background:linear-gradient(135deg,var(--tblr-primary),var(--tblr-teal))">
                    <i class="ti ti-first-aid-kit"></i>
                </span>
                {{ config('pharmacy.name') }}
            </span>
        </div>

        <div class="card card-md">
            <div class="card-body">
                <h2 class="h2 text-center mb-1">Pharmacy admin</h2>
                <p class="text-center text-secondary mb-4">Sign in with your administrator account.</p>

                <form method="POST" action="{{ route('login') }}" autocomplete="on" novalidate>
                    @csrf
                    <div class="mb-3">
                        <label class="form-label" for="email">Email</label>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                               class="form-control @error('email') is-invalid @enderror" placeholder="you@example.com">
                        @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password</label>
                        <input id="password" type="password" name="password" required autocomplete="current-password"
                               class="form-control @error('password') is-invalid @enderror" placeholder="Your password">
                        @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <label class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="remember" {{ old('remember') ? 'checked' : '' }}>
                        <span class="form-check-label">Keep me signed in on this device</span>
                    </label>
                    <button type="submit" class="btn btn-primary w-100">Sign in</button>
                </form>
            </div>
        </div>
        <p class="text-center text-secondary small mt-3">Forgot your password? Ask another administrator to reset it.</p>
    </div>
</div>
<script src="{{ asset('assets/libs/tabler-1.6.1/tabler.min.js') }}"></script>
</body>
</html>
