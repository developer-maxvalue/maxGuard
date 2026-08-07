@extends('layouts.app')

@section('title', 'Tổng quan hệ thống')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Tổng quan hệ thống</h1>
            <p>Quét, phát hiện và ưu tiên các vấn đề có thể ảnh hưởng đến việc tuân thủ AdSense trên mọi website.</p>
        </div>
        <form method="POST" action="{{ route('scans.store') }}" class="d-flex align-items-center gap-2 flex-wrap">
            @csrf
            <input type="hidden" name="site" value="all-sites">
            <input type="hidden" name="scan_type" value="full">
            @if ($aiReady)
                <input type="hidden" name="use_ai" value="1">
            @endif
            <label class="visually-hidden" for="dashboard-max-urls">Số bài viết mới tối đa mỗi website</label>
            <input id="dashboard-max-urls" class="form-control form-control-solid" style="width: 170px" type="number"
                name="max_urls" min="1" max="{{ $maxUrlSafetyLimit }}" placeholder="Bài mới / website">
            <button class="btn btn-primary px-5" type="submit">
                <i class="bi bi-upc-scan me-2"></i>Quét toàn bộ{{ $aiReady ? ' + AI' : '' }}
            </button>
        </form>
    </div>

    <div class="row g-5 mb-5">
        @foreach ($metrics as $metric)
            <div class="col-sm-6 col-xxl-3">
                <x-metric-card :label="$metric['label']" :value="$metric['value']" :note="$metric['note']" :tone="$metric['tone']" :icon="$metric['icon']" />
            </div>
        @endforeach
    </div>

    <div class="row g-5 mb-5">
        <div class="col-xl-8">
            <div class="card mg-card h-100">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Xu hướng tuân thủ</h2>
                        <p class="mg-card-subtitle">Điểm toàn hệ thống trong 12 tuần gần nhất</p>
                    </div>
                    <div class="card-toolbar">
                        <span class="badge badge-light">12 tuần gần nhất</span>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div id="compliance-trend-chart" class="mg-chart" aria-label="Biểu đồ xu hướng điểm tuân thủ"></div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mg-card h-100">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Tình trạng hệ thống</h2>
                        <p class="mg-card-subtitle">{{ $health['total'] }} website được xếp theo rủi ro hiện tại</p>
                    </div>
                </div>
                <div class="card-body pt-2">
                    <div class="mg-health-grid">
                        <div class="mg-health-donut"
                            style="--healthy: {{ $health['healthy_percent'] }}; --review: {{ $health['review_percent'] }}"
                            role="img"
                            aria-label="{{ $health['healthy'] }} tốt, {{ $health['review'] }} cần xem xét, {{ $health['critical'] }} nghiêm trọng">
                            <div><strong>{{ $health['total'] }}</strong><small>website</small></div>
                        </div>
                        <div class="mg-health-legend">
                            <div><span class="bg-success"></span><em>Tốt</em><strong>{{ $health['healthy'] }}</strong>
                            </div>
                            <div><span class="bg-warning"></span><em>Cần xem xét</em><strong>{{ $health['review'] }}</strong></div>
                            <div><span class="bg-danger"></span><em>Nghiêm trọng</em><strong>{{ $health['critical'] }}</strong>
                            </div>
                        </div>
                    </div>
                    <a href="{{ route('sites.index') }}" class="btn btn-light-primary w-100 mt-5">Xem tất cả website</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mg-card">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Website cần chú ý</h2>
                <p class="mg-card-subtitle">Sắp xếp theo điểm tuân thủ và mức độ nghiêm trọng của các vấn đề đã phát hiện</p>
            </div>
            <div class="card-toolbar">
                <a href="{{ route('sites.index') }}" class="btn btn-sm btn-light">Xem toàn hệ thống</a>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>Website</th>
                            <th>Điểm</th>
                            <th>Rủi ro lớn nhất</th>
                            <th>Phát hiện</th>
                            <th>Lần quét cuối</th>
                            <th class="text-end">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td>
                                    <a href="{{ route('sites.show', $site['slug']) }}" class="mg-site-cell">
                                        <span>{{ strtoupper(substr($site['domain'], 0, 1)) }}</span>
                                        <strong>{{ $site['domain'] }}</strong>
                                    </a>
                                </td>
                                <td><strong
                                        class="mg-score-text mg-score-{{ $site['status'] }}">{{ $site['score'] }}</strong>
                                </td>
                                <td class="text-gray-700">{{ $site['top_risk'] }}</td>
                                <td><strong>{{ $site['findings'] }}</strong></td>
                                <td class="text-muted">{{ $site['last_scan'] }}</td>
                                <td class="text-end"><x-status-badge :status="$site['status']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('page-scripts')
    <script>
        window.MaxGuardPage = {
            trend: @json($trend),
            trendLabels: ['6/5', '', '20/5', '', '3/6', '', '17/6', '', '1/7', '', '15/7', '', '29/7', '',
                '12/8', 'Hiện tại'
            ]
        };
    </script>
@endpush
