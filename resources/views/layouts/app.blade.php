<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Tổng quan') · MaxGuard</title>
    <meta name="description" content="Giám sát tuân thủ nhà xuất bản và rủi ro AdSense">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="{{ asset('vendor/metronic/assets/plugins/global/plugins.bundle.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/metronic/assets/css/style.bundle.css') }}" rel="stylesheet">
    <link href="{{ asset('css/maxguard.css') }}" rel="stylesheet">
    @stack('page-styles')
</head>

<body id="kt_body" class="mg-body">
    <div class="mg-app">
        @include('layouts.partials.sidebar')

        <div class="mg-workspace">
            @include('layouts.partials.header')

            <main class="mg-content" id="main-content">
                @if (session('status'))
                    <div class="alert alert-success d-flex align-items-center mb-6" role="alert">
                        <i class="bi bi-check-circle-fill fs-3 me-3"></i>
                        <div>{{ session('status') }}</div>
                    </div>
                @endif
                @if (session('error'))
                    <div class="alert alert-danger d-flex align-items-center mb-6" role="alert">
                        <i class="bi bi-exclamation-triangle-fill fs-3 me-3"></i>
                        <div>{{ session('error') }}</div>
                    </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    <div class="mg-sidebar-backdrop" data-mg-sidebar-close></div>

    <script src="{{ asset('vendor/metronic/assets/plugins/global/plugins.bundle.js') }}"></script>
    <script src="{{ asset('vendor/metronic/assets/js/scripts.bundle.js') }}"></script>
    <script src="{{ asset('js/maxguard.js') }}"></script>
    @stack('page-scripts')
</body>

</html>
