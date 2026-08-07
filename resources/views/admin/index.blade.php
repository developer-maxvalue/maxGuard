@extends('layouts.app')

@section('title', 'Quản trị')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Quản trị hệ thống</h1>
            <p>Giám sát người dùng và quản lý toàn bộ website, lượt quét và phát hiện trên MaxGuard.</p>
        </div>
        <a href="{{ route('admin.ai-settings.index') }}" class="btn btn-primary"><i class="bi bi-stars me-2"></i>Cài đặt AI</a>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-xl-2 col-md-4"><x-metric-card label="Người dùng" :value="number_format($metrics['users'])" note="tài khoản đã đăng ký" tone="primary" icon="bi-people" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Website" :value="number_format($metrics['sites'])" note="tất cả chủ sở hữu" tone="info" icon="bi-globe2" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Lượt quét đang chạy" :value="number_format($metrics['running_scans'])" note="đang chờ hoặc đang chạy" tone="primary" icon="bi-upc-scan" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Phát hiện đang mở" :value="number_format($metrics['open_findings'])" note="toàn hệ thống" tone="warning" icon="bi-exclamation-diamond" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Nghiêm trọng" :value="number_format($metrics['critical_findings'])" note="cần chú ý" tone="danger" icon="bi-exclamation-triangle" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Đã được AI đánh giá" :value="number_format($metrics['sites_reviewed_by_ai'])" note="website có nhận định AI" tone="success" icon="bi-stars" /></div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Người dùng</h2>
                <p class="mg-card-subtitle">20 tài khoản đăng ký gần đây nhất.</p>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead><tr><th>Người dùng</th><th>Vai trò</th><th>Website</th><th>Ngày đăng ký</th></tr></thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td><strong>{{ $user->name }}</strong><span class="d-block text-muted fs-9">{{ $user->email }}</span></td>
                                <td><span class="badge {{ $user->is_admin ? 'badge-light-danger' : 'badge-light' }}">{{ $user->is_admin ? 'Quản trị viên' : 'Người dùng' }}</span></td>
                                <td>{{ number_format($user->websites_count) }}</td>
                                <td class="text-muted">{{ $user->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-8">Không tìm thấy người dùng.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Website cần chú ý</h2>
                <p class="mg-card-subtitle">20 website có điểm thấp nhất của tất cả người dùng.</p>
            </div>
            <div class="card-toolbar"><a href="{{ route('sites.index') }}" class="btn btn-sm btn-light-primary">Quản lý tất cả website</a></div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead><tr><th>Website</th><th>Chủ sở hữu</th><th>Điểm</th><th>Trạng thái</th><th>Phát hiện</th><th>Lần quét cuối</th><th></th></tr></thead>
                    <tbody>
                        @forelse($sites as $site)
                            <tr>
                                <td><strong>{{ $site->domain }}</strong><span class="d-block text-muted fs-9">{{ $site->name }}</span></td>
                                <td>{{ $site->owner?->email ?? 'Chưa phân công' }}</td>
                                <td><strong class="mg-score-text mg-score-{{ $site->status }}">{{ $site->overall_score }}</strong></td>
                                <td><x-status-badge :status="$site->status" /></td>
                                <td>{{ number_format($site->open_findings_count) }}</td>
                                <td class="text-muted">{{ $site->last_scanned_at?->diffForHumans() ?? 'Chưa bao giờ' }}</td>
                                <td class="text-end"><a href="{{ route('sites.show', $site) }}" class="btn btn-sm btn-light-primary">Mở</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-8">Không tìm thấy website.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-5">
        <div class="col-xl-6">
            <div class="card mg-card h-100">
                <div class="card-header border-0">
                    <div class="card-title"><h2 class="mg-card-title">Lượt quét gần đây</h2></div>
                    <div class="card-toolbar"><a href="{{ route('scans.index') }}" class="btn btn-sm btn-light-primary">Tất cả lượt quét</a></div>
                </div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed gy-4 mg-table">
                            <thead><tr><th>Website / chủ sở hữu</th><th>Trạng thái</th><th>Tiến độ</th><th></th></tr></thead>
                            <tbody>
                                @forelse($scans as $scan)
                                    <tr>
                                        <td><strong>{{ $scan->website->domain }}</strong><span class="d-block text-muted fs-9">{{ $scan->website->owner?->email ?? 'Chưa phân công' }}</span></td>
                                        <td><x-status-badge :status="$scan->status" /></td>
                                        <td>{{ $scan->progress }}%</td>
                                        <td class="text-end"><a href="{{ route('scans.show', $scan) }}" class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-8">Không tìm thấy lượt quét.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card mg-card h-100">
                <div class="card-header border-0">
                    <div class="card-title"><h2 class="mg-card-title">Phát hiện ưu tiên</h2></div>
                    <div class="card-toolbar"><a href="{{ route('findings.index') }}" class="btn btn-sm btn-light-primary">Tất cả phát hiện</a></div>
                </div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed gy-4 mg-table">
                            <thead><tr><th>Vấn đề / chủ sở hữu</th><th>Mức độ</th><th>Độ tin cậy</th><th></th></tr></thead>
                            <tbody>
                                @forelse($findings as $finding)
                                    <tr>
                                        <td><strong>{{ \App\Support\UiText::text($finding->title) }}</strong><span class="d-block text-muted fs-9">{{ $finding->website->domain }} · {{ $finding->website->owner?->email ?? 'Chưa phân công' }}</span></td>
                                        <td><x-status-badge :status="$finding->severity" /></td>
                                        <td>{{ $finding->confidence }}%</td>
                                        <td class="text-end"><a href="{{ route('findings.show', $finding) }}" class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-8">Không có phát hiện đang mở.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
