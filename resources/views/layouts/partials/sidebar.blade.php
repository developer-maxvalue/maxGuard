<aside class="mg-sidebar" id="mg-sidebar" aria-label="Điều hướng chính">
    <div class="mg-brand">
        <a href="{{ route('sites.index') }}" class="mg-brand-link" aria-label="Danh sách website MaxGuard">
            <span class="mg-brand-mark">M</span>
            <span>
                <strong>MaxGuard</strong>
                <small>Tuân thủ nhà xuất bản</small>
            </span>
        </a>
        <button class="btn btn-sm btn-icon btn-active-color-primary d-lg-none" data-mg-sidebar-close
            aria-label="Đóng trình đơn">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="mg-nav">
        <span class="mg-nav-label">Không gian làm việc</span>

        <a class="mg-nav-link {{ request()->routeIs('sites.*') ? 'active' : '' }}" href="{{ route('sites.index') }}">
            <i class="bi bi-globe2"></i><span>Website</span>
        </a>
        @if (auth()->user()?->is_admin)
            <span class="mg-nav-label mt-5">Quản trị</span>
            <a class="mg-nav-link {{ request()->routeIs('admin.index') ? 'active' : '' }}" href="{{ route('admin.index') }}">
                <i class="bi bi-sliders"></i><span>Quản trị hệ thống</span>
            </a>
            <a class="mg-nav-link {{ request()->routeIs('admin.ai-settings.*') ? 'active' : '' }}" href="{{ route('admin.ai-settings.index') }}">
                <i class="bi bi-stars"></i><span>Cài đặt AI</span>
            </a>
        @endif
    </nav>
</aside>
