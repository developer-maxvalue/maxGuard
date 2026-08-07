<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Đăng nhập · MaxGuard</title>
    <meta name="description" content="Đăng nhập không gian quản lý tuân thủ nhà xuất bản MaxGuard">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('vendor/metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/metronic/assets/css/style.bundle.css') }}" rel="stylesheet">
    <link href="{{ asset('css/maxguard.css') }}" rel="stylesheet">
</head>

<body class="mg-auth-body">
    <main class="mg-auth-shell">
        <section class="mg-auth-brand-panel" aria-label="Giới thiệu MaxGuard">
            <div>
                <a href="{{ url('/') }}" class="mg-auth-brand">
                    <span class="mg-brand-mark">M</span>
                    <span><strong>MaxGuard</strong><small>Tuân thủ nhà xuất bản</small></span>
                </a>

                <div class="mg-auth-message">
                    <span class="mg-auth-kicker"><i class="bi bi-shield-check"></i> Trung tâm kiểm soát tuân thủ</span>
                    <h1>Bảo vệ mọi website trước khi rủi ro chính sách gây thất thoát doanh thu.</h1>
                    <p>Giám sát chất lượng nội dung, tín hiệu bản quyền, trang trùng lặp, trải nghiệm quảng cáo, quyền riêng tư và độ tin cậy kỹ thuật tại một nơi.</p>
                </div>
            </div>

            <div class="mg-auth-trust">
                <span><i class="bi bi-lock-fill"></i> Bằng chứng riêng tư</span>
                <span><i class="bi bi-diagram-3-fill"></i> Quét theo hàng đợi</span>
                <span><i class="bi bi-fingerprint"></i> Toàn vẹn SHA-256</span>
            </div>
        </section>

        <section class="mg-auth-form-panel">
            <div class="mg-auth-card">
                <div class="mg-auth-mobile-brand">
                    <span class="mg-brand-mark">M</span>
                    <strong>MaxGuard</strong>
                </div>

                <span class="mg-eyebrow">Không gian bảo mật</span>
                <h2>Chào mừng trở lại</h2>
                <p class="mg-auth-lead">Đăng nhập bằng tài khoản quản trị viên đã tạo cho hệ thống này.</p>

                @if (session('status'))
                    <div class="alert alert-success" role="status">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('login.store') }}" class="mg-auth-form">
                    @csrf

                    <div>
                        <label class="form-label" for="email">Địa chỉ email</label>
                        <input id="email" class="form-control form-control-lg @error('email') is-invalid @enderror"
                            type="email" name="email" value="{{ old('email') }}" required autofocus
                            autocomplete="username" placeholder="admin@example.com">
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div>
                        <label class="form-label" for="password">Mật khẩu</label>
                        <input id="password"
                            class="form-control form-control-lg @error('password') is-invalid @enderror" type="password"
                            name="password" required autocomplete="current-password" placeholder="Nhập mật khẩu">
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <label class="form-check form-check-custom form-check-solid">
                        <input class="form-check-input" type="checkbox" name="remember" value="1"
                            @checked(old('remember'))>
                        <span class="form-check-label">Duy trì đăng nhập trên thiết bị này</span>
                    </label>

                    <button class="btn btn-primary btn-lg w-100" type="submit">
                        Đăng nhập an toàn <i class="bi bi-arrow-right ms-2"></i>
                    </button>
                </form>

                <p class="mg-auth-help"><i class="bi bi-info-circle"></i> Chưa có tài khoản? Chạy lệnh <code>php artisan
                        maxguard:create-admin</code> trên máy chủ.</p>
            </div>
        </section>
    </main>
</body>

</html>
