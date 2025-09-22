<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="dns-prefetch" href="//fonts.bunny.net">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">

    <!-- AdminLTE CSS -->
    @vite([
        'resources/css/app.css',
        'resources/js/app.js',
        'resources/adminlte/css/adminlte.min.css',
        'resources/adminlte/plugins/fontawesome-free/css/all.min.css',
    ])
</head>

<body class="hold-transition login-page" style="background-color: #f8f9fa; font-family: 'Poppins', sans-serif;">
    <div id="app">
        <main class="py-4">
            <div class="login-box" style="width: 400px; margin: 0 auto;">
                <div class="card" style="border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: none;">
                    <div class="card-body login-card-body" style="padding: 2.5rem;">
                        <div class="text-center mb-4">
                            <h3 style="color: #4e73df; font-weight: 600; margin-bottom: 1.5rem;">Welcome Back</h3>
                            <p style="color: #6c757d;">Please enter your credentials to login</p>
                        </div>

                        <form method="POST" action="{{ route('login') }}">
                            @csrf
                            
                            <div class="mb-3">
                                <label for="email" style="font-weight: 500; color: #495057; margin-bottom: 0.5rem;">Email Address</label>
                                <input id="email" type="email" class="form-control @error('email') is-invalid @enderror" 
                                       name="email" value="{{ old('email') }}" required autocomplete="email" autofocus
                                       style="padding: 12px 15px; border-radius: 8px; border: 1px solid #ddd; transition: all 0.3s;">
                                @error('email')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="password" style="font-weight: 500; color: #495057; margin-bottom: 0.5rem;">Password</label>
                                <input id="password" type="password" class="form-control @error('password') is-invalid @enderror" 
                                       name="password" required autocomplete="current-password"
                                       style="padding: 12px 15px; border-radius: 8px; border: 1px solid #ddd; transition: all 0.3s;">
                                @error('password')
                                    <span class="invalid-feedback" role="alert">
                                        <strong>{{ $message }}</strong>
                                    </span>
                                @enderror
                            </div>

                            <div class="mb-3 d-flex justify-content-between align-items-center">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="remember" style="color: #6c757d;">
                                        Remember Me
                                    </label>
                                </div>
                                
                                @if (Route::has('password.request'))
                                    <a href="{{ route('password.request') }}" style="color: #4e73df; text-decoration: none; font-size: 0.875rem;">
                                        Forgot Your Password?
                                    </a>
                                @endif
                            </div>

                            <div class="mb-3">
                                <button type="submit" class="btn btn-primary w-100" 
                                        style="background-color: #4e73df; border: none; padding: 12px; border-radius: 8px; font-weight: 500; letter-spacing: 0.5px; transition: all 0.3s;">
                                    Login
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- AdminLTE Scripts -->
    <script src="{{ asset('adminlte/plugins/jquery/jquery.min.js') }}"></script>
    <script src="{{ asset('adminlte/plugins/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('adminlte/dist/js/adminlte.min.js') }}"></script>
</body>
</html>