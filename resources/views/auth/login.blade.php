<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Sign in · MaxGuard</title>
    <meta name="description" content="Sign in to the MaxGuard publisher compliance workspace">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('vendor/metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/metronic/assets/css/style.bundle.css') }}" rel="stylesheet">
    <link href="{{ asset('css/maxguard.css') }}" rel="stylesheet">
</head>

<body class="mg-auth-body">
    <main class="mg-auth-shell">
        <section class="mg-auth-brand-panel" aria-label="MaxGuard introduction">
            <div>
                <a href="{{ url('/') }}" class="mg-auth-brand">
                    <span class="mg-brand-mark">M</span>
                    <span><strong>MaxGuard</strong><small>Publisher Compliance</small></span>
                </a>

                <div class="mg-auth-message">
                    <span class="mg-auth-kicker"><i class="bi bi-shield-check"></i> Compliance command center</span>
                    <h1>Protect every website before policy risk becomes revenue loss.</h1>
                    <p>Monitor content quality, copyright signals, duplicate pages, ad experience, privacy and technical trust from one workspace.</p>
                </div>
            </div>

            <div class="mg-auth-trust">
                <span><i class="bi bi-lock-fill"></i> Private evidence</span>
                <span><i class="bi bi-diagram-3-fill"></i> Queue-based scans</span>
                <span><i class="bi bi-fingerprint"></i> SHA-256 integrity</span>
            </div>
        </section>

        <section class="mg-auth-form-panel">
            <div class="mg-auth-card">
                <div class="mg-auth-mobile-brand">
                    <span class="mg-brand-mark">M</span>
                    <strong>MaxGuard</strong>
                </div>

                <span class="mg-eyebrow">Secure workspace</span>
                <h2>Welcome back</h2>
                <p class="mg-auth-lead">Sign in with the administrator account created for this installation.</p>

                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mg-auth-form">
                    @csrf

                    <div>
                        <label class="form-label" for="email">Email address</label>
                        <input id="email" class="form-control form-control-lg @error('email') is-invalid @enderror" type="email" name="email"
                            value="{{ old('email') }}" required autofocus autocomplete="username" placeholder="admin@example.com">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label" for="password">Password</label>
                        <input id="password" class="form-control form-control-lg @error('password') is-invalid @enderror" type="password" name="password"
                            required autocomplete="current-password" placeholder="Enter your password">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <label class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                        <span class="form-check-label">Keep me signed in on this device</span>
                    </label>

                    <button class="btn btn-primary btn-lg w-100" type="submit">
                        Sign in securely <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </form>

                <p class="mg-auth-help"><i class="bi bi-info-circle"></i> No account yet? Run <code>php artisan maxguard:create-admin</code> on the server.</p>
            </div>
        </section>
    </main>
</body>

</html>
