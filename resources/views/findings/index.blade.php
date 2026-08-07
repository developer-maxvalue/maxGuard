@extends('layouts.app')

@section('title', 'Phát hiện')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Phát hiện và hồ sơ</h1>
            <p>Xem xét rủi ro chính sách, kiểm tra bằng chứng và theo dõi quá trình khắc phục đến khi hoàn tất.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('findings.export.xlsx', request()->except(['page', 'export'])) }}"
                class="btn btn-light-success"><i class="bi bi-file-earmark-excel me-2"></i>Xuất Excel</a>
            <a href="{{ route('findings.index', array_merge(request()->except('page'), ['export' => 'csv'])) }}"
                class="btn btn-light"><i class="bi bi-download me-2"></i>CSV</a>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-3">
            <div class="card mg-card">
                <div class="card-body p-5">
                    <div class="mg-eyebrow">Nghiêm trọng</div>
                    <div class="d-flex align-items-end justify-content-between mt-3"><strong
                            class="mg-filter-number text-danger">{{ $counts['critical'] }}</strong><span
                            class="badge badge-light-danger">Đang mở</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mg-card">
                <div class="card-body p-5">
                    <div class="mg-eyebrow">Cao</div>
                    <div class="d-flex align-items-end justify-content-between mt-3"><strong
                            class="mg-filter-number text-warning">{{ $counts['high'] }}</strong><span
                            class="text-muted fs-7">Đang mở</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mg-card">
                <div class="card-body p-5">
                    <div class="mg-eyebrow">Đang khắc phục</div>
                    <div class="d-flex align-items-end justify-content-between mt-3"><strong
                            class="mg-filter-number text-primary">{{ $counts['remediating'] }}</strong><span
                            class="text-muted fs-7">Đang hoạt động</span></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card mg-card">
                <div class="card-body p-5">
                    <div class="mg-eyebrow">Đã xử lý trong tháng</div>
                    <div class="d-flex align-items-end justify-content-between mt-3"><strong
                            class="mg-filter-number text-success">{{ $counts['resolved_month'] }}</strong><span
                            class="badge badge-light-success">Đã xác minh</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mg-card">
        <form class="card-header border-0 pt-3" method="GET">
            <div class="card-title">
                <div class="position-relative"><i
                        class="bi bi-search position-absolute top-50 translate-middle-y ms-4 text-muted"></i><input
                        name="q" value="{{ request('q') }}" class="form-control form-control-solid ps-10 w-300px"
                        placeholder="Tìm phát hiện, URL hoặc website"></div>
            </div>
            <div class="card-toolbar d-flex gap-3"><select name="severity" class="form-select form-select-solid w-150px"
                    onchange="this.form.submit()">
                    <option value="">Tất cả mức độ</option>
                    <option value="critical" @selected(request('severity') === 'critical')>Nghiêm trọng</option>
                    <option value="high" @selected(request('severity') === 'high')>Cao</option>
                    <option value="review" @selected(request('severity') === 'review')>Cần xem xét</option>
                </select><select name="category" class="form-select form-select-solid w-180px"
                    onchange="this.form.submit()">
                    <option value="">Tất cả danh mục</option>
                    <option value="Copyright" @selected(request('category') === 'Copyright')>Bản quyền</option>
                    <option value="Duplicate content" @selected(request('category') === 'Duplicate content')>Nội dung trùng lặp</option>
                    <option value="Ad experience" @selected(request('category') === 'Ad experience')>Trải nghiệm quảng cáo</option>
                    <option value="Content quality" @selected(request('category') === 'Content quality')>Chất lượng nội dung</option>
                    <option value="Privacy & consent" @selected(request('category') === 'Privacy & consent')>Quyền riêng tư và đồng ý</option>
                    <option value="Prohibited content" @selected(request('category') === 'Prohibited content')>Nội dung bị cấm</option>
                    <option value="Deceptive practices" @selected(request('category') === 'Deceptive practices')>Hành vi lừa đảo</option>
                    <option value="Technical trust" @selected(request('category') === 'Technical trust')>Độ tin cậy kỹ thuật</option>
                </select></div>
        </form>
        <div class="card-body pt-1">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-5 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>Phát hiện</th>
                            <th>Website</th>
                            <th>Danh mục</th>
                            <th>Bộ phân tích</th>
                            <th>Mức độ</th>
                            <th>Độ tin cậy</th>
                            <th>Thời điểm phát hiện</th>
                            <th>Trạng thái</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($findings as $finding)
                            <tr>
                                <td><a href="{{ route('findings.show', $finding['id']) }}"
                                        class="d-block fw-bold text-gray-900 text-hover-primary">{{ $finding['title'] }}</a><span
                                        class="d-block text-muted fs-8 text-break mw-500px" title="{{ $finding['url'] }}">{{ $finding['url'] }}</span>
                                </td>
                                <td class="fw-semibold">{{ $finding['site'] }}</td>
                                <td><span class="badge badge-light">{{ $finding['category'] }}</span></td>
                                <td><span
                                        class="badge {{ $finding['source'] === 'AI' ? 'badge-light-info' : 'badge-light' }}">{{ $finding['source'] }}</span>
                                </td>
                                <td><x-status-badge :status="$finding['severity']" /></td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="progress h-6px w-60px">
                                            <div class="progress-bar bg-primary"
                                                style="width: {{ $finding['confidence'] }}%"></div>
                                        </div><strong>{{ $finding['confidence'] }}%</strong>
                                    </div>
                                </td>
                                <td class="text-muted">{{ $finding['detected'] }}</td>
                                <td><x-status-badge :status="$finding['status']" /></td>
                                <td class="text-end"><a class="btn btn-sm btn-icon btn-light"
                                        href="{{ route('findings.show', $finding['id']) }}"><i
                                            class="bi bi-arrow-right"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (method_exists($findings, 'links'))
                <div class="mt-5">{{ $findings->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    </div>
@endsection
