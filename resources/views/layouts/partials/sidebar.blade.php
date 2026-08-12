@php
    $sidebarSites = \App\Models\Website::query()->accessibleBy(auth()->id());
    $sidebarTotal = (clone $sidebarSites)->count();
    $sidebarMonitored = (clone $sidebarSites)->whereNotNull('last_scanned_at')->count();
    $sidebarDiscovered = (int) (clone $sidebarSites)->sum('last_discovered_pages');
    $sidebarScanned = (int) (clone $sidebarSites)->sum('last_scanned_pages');
    $sidebarCritical = \App\Models\Finding::query()
        ->whereHas('website', fn($query) => $query->accessibleBy(auth()->id()))
        ->open()
        ->where('severity', 'critical')
        ->count();
    $sidebarCoverage =
        $sidebarDiscovered > 0
            ? min(100, (int) round(($sidebarScanned / $sidebarDiscovered) * 100))
            : ($sidebarTotal > 0
                ? (int) round(($sidebarMonitored / $sidebarTotal) * 100)
                : 0);
    $sidebarSiteIds = (clone $sidebarSites)->pluck('id');
    $sidebarRequiredTypes = \App\Support\EssentialPublisherPages::types();
    $sidebarRequiredTotal = $sidebarSiteIds->count() * count($sidebarRequiredTypes);
    $sidebarRequiredBySite = [];
    if ($sidebarSiteIds->isNotEmpty()) {
        \App\Models\Page::query()
            ->whereIn('website_id', $sidebarSiteIds)
            ->whereNotNull('last_scanned_at')
            ->get(['website_id', 'url', 'meta'])
            ->each(function ($page) use (&$sidebarRequiredBySite): void {
                $type = data_get($page->meta, 'essential_page_type')
                    ?: \App\Support\EssentialPublisherPages::classify($page->url);
                if ($type !== null) {
                    $sidebarRequiredBySite[$page->website_id][$type] = true;
                }
            });
    }
    $sidebarRequiredChecked = collect($sidebarRequiredBySite)->sum(fn($types) => count($types));
    $sidebarRequiredMissing = max(0, $sidebarRequiredTotal - $sidebarRequiredChecked);
    $sidebarRequiredIssues = \App\Models\Finding::query()
        ->whereHas('website', fn($query) => $query->accessibleBy(auth()->id()))
        ->open()
        ->where('rule_key', 'like', 'publisher.%')
        ->count();
    $sidebarRequiredCoverage = $sidebarRequiredTotal > 0
        ? min(100, (int) round(($sidebarRequiredChecked / $sidebarRequiredTotal) * 100))
        : 0;
@endphp

<aside class="mg-sidebar" id="mg-sidebar" aria-label="Điều hướng chính">
    <div class="mg-brand">
        <a href="{{ route('dashboard') }}" class="mg-brand-link" aria-label="Bảng điều khiển MaxGuard">
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

        <a class="mg-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
            <i class="bi bi-grid"></i><span>Tổng quan</span>
        </a>
        <a class="mg-nav-link {{ request()->routeIs('sites.*') ? 'active' : '' }}" href="{{ route('sites.index') }}">
            <i class="bi bi-globe2"></i><span>Website</span>
        </a>
        <a class="mg-nav-link {{ request()->routeIs('findings.*') ? 'active' : '' }}"
            href="{{ route('findings.index') }}">
            <i class="bi bi-exclamation-triangle"></i><span>Phát hiện</span>
            @if ($sidebarCritical > 0)
                <span class="mg-nav-count">{{ $sidebarCritical }}</span>
            @endif
        </a>
        <a class="mg-nav-link {{ request()->routeIs('scans.*') ? 'active' : '' }}" href="{{ route('scans.index') }}">
            <i class="bi bi-upc-scan"></i><span>Trung tâm quét</span>
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
        <a class="mg-nav-link" href="{{ route('findings.export.xlsx') }}">
            <i class="bi bi-file-earmark-excel"></i><span>Báo cáo Excel</span>
        </a>
    </nav>

    <div class="mg-coverage-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-semibold text-white">Phạm vi</span>
            <span class="mg-live-dot">Trực tiếp</span>
        </div>
        <div class="fs-7 text-white-50 mb-4">Đã quét {{ number_format($sidebarScanned) }} /
            {{ number_format($sidebarDiscovered) }} trang được phát hiện</div>
        <div class="progress h-6px bg-white bg-opacity-10">
            <div class="progress-bar bg-info" role="progressbar" style="width: {{ $sidebarCoverage }}%"
                aria-valuenow="{{ $sidebarCoverage }}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="fs-8 text-info mt-3">Phạm vi URL đã quét {{ $sidebarCoverage }}%</div>
        <div class="border-top border-white border-opacity-10 mt-4 pt-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="fs-7 fw-semibold text-white">Trang bắt buộc</span>
                <span class="fs-8 {{ $sidebarRequiredIssues > 0 || $sidebarRequiredMissing > 0 ? 'text-warning' : 'text-success' }}">
                    {{ $sidebarRequiredChecked }}/{{ $sidebarRequiredTotal }}
                </span>
            </div>
            <div class="progress h-6px bg-white bg-opacity-10">
                <div class="progress-bar {{ $sidebarRequiredIssues > 0 || $sidebarRequiredMissing > 0 ? 'bg-warning' : 'bg-success' }}"
                    role="progressbar" style="width: {{ $sidebarRequiredCoverage }}%"
                    aria-valuenow="{{ $sidebarRequiredCoverage }}" aria-valuemin="0" aria-valuemax="100"></div>
            </div>
            <div class="fs-8 text-white-50 mt-3">
                @if ($sidebarRequiredTotal === 0)
                    Chưa có website để kiểm tra
                @elseif ($sidebarRequiredMissing > 0 || $sidebarRequiredIssues > 0)
                    Thiếu {{ $sidebarRequiredMissing }} · {{ $sidebarRequiredIssues }} vấn đề nội dung
                @else
                    Đã quét và không phát hiện thiếu sót
                @endif
            </div>
        </div>
    </div>
</aside>
