<header class="mg-header">
    <div class="d-flex align-items-center gap-3 flex-grow-1">
        <button class="btn btn-sm btn-icon btn-light d-lg-none" data-mg-sidebar-open aria-label="Open navigation">
            <i class="bi bi-list fs-2"></i>
        </button>

        <form class="mg-search" method="GET" action="{{ route('findings.index') }}" role="search">
            <i class="bi bi-search"></i>
            <input type="search" name="q" value="{{ request()->routeIs('findings.*') ? request('q') : '' }}"
                placeholder="Search sites, URLs or findings…" aria-label="Global search">
            <kbd>⌘ K</kbd>
        </form>
    </div>

    <div class="d-flex align-items-center gap-3">
        <a class="btn btn-icon btn-light position-relative"
            href="{{ route('findings.index', ['severity' => 'critical']) }}" aria-label="Critical findings">
            <i class="bi bi-bell fs-4"></i>
            <span class="mg-notification-dot"></span>
        </a>
        @php
            $displayName = auth()->user()?->name ?? 'Administrator';
            $initials = collect(preg_split('/\s+/', trim($displayName)))
                ->filter()
                ->take(2)
                ->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');
        @endphp
        <button class="mg-user" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="mg-avatar">{{ $initials ?: 'AD' }}</span>
            <span class="d-none d-sm-block text-start">
                <strong>{{ $displayName }}</strong>
                <small>Administrator</small>
            </span>
            <i class="bi bi-chevron-down text-muted d-none d-sm-block"></i>
        </button>
        <div class="dropdown-menu dropdown-menu-end shadow-sm">
            <div class="px-4 py-3">
                <strong class="d-block">{{ $displayName }}</strong>
                <span class="text-muted fs-8">{{ auth()->user()?->email }}</span>
            </div>
            @if (Route::has('logout'))
                <div class="dropdown-divider"></div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="dropdown-item" type="submit"><i class="bi bi-box-arrow-right me-2"></i>Sign
                        out</button>
                </form>
            @endif
        </div>
    </div>
</header>
