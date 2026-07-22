@php
    $sidebarSites = \App\Models\Website::query()->accessibleBy(auth()->id());
    $sidebarTotal = (clone $sidebarSites)->count();
    $sidebarMonitored = (clone $sidebarSites)->whereNotNull('last_scanned_at')->count();
    $sidebarCritical = \App\Models\Finding::query()
        ->whereHas('website', fn($query) => $query->accessibleBy(auth()->id()))
        ->open()
        ->where('severity', 'critical')
        ->count();
    $sidebarCoverage = $sidebarTotal > 0 ? (int) round(($sidebarMonitored / $sidebarTotal) * 100) : 0;
@endphp

<aside class="mg-sidebar" id="mg-sidebar" aria-label="Primary navigation">
    <div class="mg-brand">
        <a href="{{ route('dashboard') }}" class="mg-brand-link" aria-label="MaxGuard dashboard">
            <span class="mg-brand-mark">M</span>
            <span>
                <strong>MaxGuard</strong>
                <small>Publisher compliance</small>
            </span>
        </a>
        <button class="btn btn-sm btn-icon btn-active-color-primary d-lg-none" data-mg-sidebar-close aria-label="Close menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="mg-nav">
        <span class="mg-nav-label">Workspace</span>

        <a class="mg-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <i class="bi bi-grid"></i><span>Overview</span>
        </a>
        <a class="mg-nav-link {{ request()->routeIs('sites.*') ? 'active' : '' }}" href="{{ route('sites.index') }}">
            <i class="bi bi-globe2"></i><span>Sites</span>
        </a>
        <a class="mg-nav-link {{ request()->routeIs('findings.*') ? 'active' : '' }}" href="{{ route('findings.index') }}">
            <i class="bi bi-exclamation-triangle"></i><span>Findings</span>
            @if ($sidebarCritical > 0)
                <span class="mg-nav-count">{{ $sidebarCritical }}</span>
            @endif
        </a>
        <a class="mg-nav-link {{ request()->routeIs('scans.*') ? 'active' : '' }}" href="{{ route('scans.index') }}">
            <i class="bi bi-upc-scan"></i><span>Scan center</span>
        </a>
        <a class="mg-nav-link" href="{{ route('findings.index') }}">
            <i class="bi bi-file-earmark-check"></i><span>Evidence</span>
        </a>
        <a class="mg-nav-link" href="{{ route('findings.index', ['export' => 'csv']) }}">
            <i class="bi bi-bar-chart"></i><span>Reports</span>
        </a>
    </nav>

    <div class="mg-coverage-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-semibold text-white">Coverage</span>
            <span class="mg-live-dot">Live</span>
        </div>
        <div class="fs-7 text-white-50 mb-4">{{ $sidebarMonitored }} of {{ $sidebarTotal }} sites monitored</div>
        <div class="progress h-6px bg-white bg-opacity-10">
            <div class="progress-bar bg-info" role="progressbar" style="width: {{ $sidebarCoverage }}%" aria-valuenow="{{ $sidebarCoverage }}"
                aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="fs-8 text-info mt-3">{{ $sidebarCoverage }}% portfolio coverage</div>
    </div>
</aside>
