@extends('layouts.app')

@section('title', 'Website')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Website</h1>
            <p>Giám sát tình trạng tuân thủ AdSense và phạm vi quét trên toàn hệ thống.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWebsiteModal"><i
                class="bi bi-plus-lg me-2"></i>Thêm website</button>
    </div>

    <div class="card mg-card">
        <form class="card-header border-0 pt-3" method="GET">
            <div class="card-title">
                <div class="position-relative">
                    <i class="bi bi-search position-absolute top-50 translate-middle-y ms-4 text-muted"></i>
                    <input type="search" name="q" value="{{ request('q') }}"
                        class="form-control form-control-solid ps-10 w-300px" placeholder="Tìm tên miền"
                        aria-label="Tìm website">
                </div>
            </div>
            <div class="card-toolbar d-flex gap-3">
                <select name="status" class="form-select form-select-solid w-150px" onchange="this.form.submit()">
                    <option value="">Tất cả trạng thái</option>
                    <option value="critical" @selected(request('status') === 'critical')>Nghiêm trọng</option>
                    <option value="high" @selected(request('status') === 'high')>Cao</option>
                    <option value="review" @selected(request('status') === 'review')>Cần xem xét</option>
                    <option value="healthy" @selected(request('status') === 'healthy')>Tốt</option>
                    <option value="pending" @selected(request('status') === 'pending')>Đang chờ</option>
                </select>
                <button class="btn btn-light" name="export" value="csv"><i
                        class="bi bi-download me-2"></i>Xuất dữ liệu</button>
            </div>
        </form>
        <div class="card-body pt-2">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-5 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>Website</th>
                            <th>Tình trạng</th>
                            <th>Trang</th>
                            <th>Phạm vi</th>
                            <th>Vấn đề ưu tiên</th>
                            <th>Lần quét cuối</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td>
                                    <a href="{{ route('sites.show', $site['slug']) }}" class="mg-site-cell">
                                        <span>{{ strtoupper(substr($site['domain'], 0, 1)) }}</span>
                                        <div><strong>{{ $site['domain'] }}</strong><small>{{ $site['findings'] }} phát hiện đang mở</small></div>
                                    </a>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-3"><strong
                                            class="mg-score-text mg-score-{{ $site['status'] }}">{{ $site['score'] }}</strong><x-status-badge
                                            :status="$site['status']" />
                                    </div>
                                </td>
                                <td><strong>{{ number_format($site['pages']) }}</strong><span
                                        class="d-block text-muted fs-9">/ {{ number_format($site['discovered_pages']) }} trang được phát hiện</span></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="progress w-80px h-6px">
                                            <div class="progress-bar bg-primary" style="width: {{ $site['coverage'] }}%">
                                            </div>
                                        </div><span
                                            class="{{ $site['coverage_partial'] ? 'text-warning' : '' }}">{{ $site['coverage'] }}%</span>
                                    </div>
                                </td>
                                <td class="text-gray-700">{{ $site['top_risk'] }}</td>
                                <td class="text-muted">{{ $site['last_scan'] }}</td>
                                <td class="text-end"><a href="{{ route('sites.show', $site['slug']) }}"
                                        class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (method_exists($sites, 'links'))
                <div class="mt-5">{{ $sites->links() }}</div>
            @endif
        </div>
    </div>

    <div class="modal fade" id="addWebsiteModal" tabindex="-1" aria-labelledby="addWebsiteTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('sites.store') }}">
                @csrf
                <div class="modal-header border-0">
                    <div>
                        <h2 class="modal-title" id="addWebsiteTitle">Thêm website</h2>
                        <p class="text-muted fs-7 mb-0 mt-2">Chỉ thêm tên miền bạn sở hữu hoặc được phép kiểm tra.</p>
                    </div><button type="button" class="btn btn-sm btn-icon btn-light" data-bs-dismiss="modal"><i
                            class="bi bi-x-lg"></i></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="mb-5"><label class="form-label fw-semibold">Tên hiển thị</label><input type="text"
                            name="name" value="{{ old('name') }}"
                            class="form-control form-control-solid @error('name') is-invalid @enderror"
                            placeholder="Website nhà xuất bản">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-5"><label class="form-label fw-semibold">URL bắt đầu</label><input type="url"
                            name="start_url" value="{{ old('start_url') }}"
                            class="form-control form-control-solid @error('start_url') is-invalid @enderror"
                            placeholder="https://example.com/">
                        @error('start_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            IP riêng, localhost và cổng không tiêu chuẩn sẽ bị chặn.</div>
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-light"
                        data-bs-dismiss="modal">Hủy</button><button class="btn btn-primary">Thêm website</button></div>
            </form>
        </div>
    </div>
@endsection

@if ($errors->any())
    @push('page-scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                new bootstrap.Modal(document.getElementById('addWebsiteModal')).show();
            });
        </script>
    @endpush
@endif
