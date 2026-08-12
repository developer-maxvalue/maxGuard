@extends('layouts.app')

@section('title', $site['domain'])

@section('content')
    <div class="mg-breadcrumb"><a href="{{ route('sites.index') }}">Website</a><i
            class="bi bi-chevron-right"></i><span>{{ $site['domain'] }}</span></div>
    <div class="mg-page-heading align-items-end">
        <div>
            <div class="d-flex align-items-center flex-wrap gap-3">
                <h1 class="mb-0">{{ $site['domain'] }}</h1>
                <x-status-badge :status="$site['status']" />
            </div>
            <p class="mt-2 mb-0">Quét lần cuối {{ $site['last_scan'] }} · Đã phân tích {{ number_format($site['pages']) }} trang
            </p>
        </div>
        <div class="d-flex gap-3">
            <form method="POST" action="{{ route('sites.ai-assessment', $site['slug']) }}">
                @csrf
                <button class="btn btn-light-primary" @disabled(!$aiReady || !$aiAssessmentScan)
                    title="{{ !$aiReady ? 'AI chưa được cấu hình' : (!$aiAssessmentScan ? 'Cần quét website trước' : 'Đọc lại toàn bộ chỉ số và vấn đề của lượt quét gần nhất') }}">
                    <i class="bi bi-stars me-2"></i>{{ $aiAssessment ? 'AI đánh giá lại' : 'AI đánh giá' }}
                </button>
            </form>
            <a href="{{ route('findings.index', ['q' => $site['domain']]) }}" class="btn btn-light"><i
                    class="bi bi-folder2-open me-2"></i>Hồ sơ đang mở</a>
            <form method="POST" action="{{ route('scans.store') }}">
                @csrf
                <input type="hidden" name="site" value="{{ $site['domain'] }}">
                <input type="hidden" name="scan_type" value="full">
                @if ($aiReady)
                    <input type="hidden" name="use_ai" value="1">
                @endif
                <div class="input-group">
                    <input class="form-control form-control-solid" style="max-width: 150px" type="number" name="max_urls"
                        min="1" max="{{ $maxUrlSafetyLimit }}" placeholder="Bài viết mới nhất">
                    <button class="btn btn-primary"><i
                            class="bi bi-arrow-repeat me-2"></i>Quét lại{{ $aiReady ? ' + AI' : '' }}</button>
                </div>
            </form>
            <form method="POST" action="{{ route('sites.destroy', $site['slug']) }}"
                onsubmit="return confirm('Xóa {{ addslashes($site['domain']) }} cùng toàn bộ lượt quét, trang, phát hiện và bằng chứng? Không thể hoàn tác thao tác này.')">
                @csrf
                @method('DELETE')
                <button class="btn btn-light-danger"><i class="bi bi-trash me-2"></i>Xóa website</button>
            </form>
        </div>
    </div>

    @error('ai')
        <div class="alert alert-danger d-flex align-items-start gap-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-3"></i><div>{{ $message }}</div>
        </div>
    @enderror

    @if ($site['coverage_partial'])
        <div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-3"></i>
            <div><strong class="d-block mb-1">Lần quét gần nhất chưa bao phủ toàn bộ</strong>Đã quét
                {{ number_format($site['pages']) }} /
                {{ number_format($site['discovered_pages']) }} URL được phát hiện. Hãy kiểm tra dữ liệu lượt quét mới nhất, lỗi sitemap, robots.txt và giới hạn đã cấu hình.
            </div>
        </div>
    @endif

    <div class="row g-5 mb-5">
        <div class="col-xl-3 col-md-6">
            <div class="card mg-card h-100">
                <div class="card-body p-6">
                    <div class="mg-eyebrow mb-4">Điểm tổng thể</div>
                    <div class="d-flex align-items-center gap-5"><x-score-ring :score="$site['score']" />
                        <div><strong class="mg-score-{{ $site['status'] }} d-block mb-2">Mức rủi ro
                                {{ ['critical' => 'nghiêm trọng', 'high' => 'cao', 'review' => 'cần xem xét', 'healthy' => 'thấp', 'pending' => 'đang chờ'][$site['status']] ?? $site['status'] }}</strong><span class="text-muted fs-7">Ưu tiên các phát hiện có độ tin cậy cao nhất trước.</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6"><x-metric-card label="Độ phủ lượt quét" :value="$site['coverage'].'%'"
                :note="number_format($site['pages']).' / '.number_format($site['discovered_pages']).' URL'" tone="info" icon="bi-bullseye" /></div>
        <div class="col-xl-3 col-md-6"><x-metric-card label="Trang đã phân tích" :value="number_format($site['pages'])" :note="$site['coverage'] . '% trong ' . number_format($site['discovered_pages']) . ' URL được phát hiện'"
                tone="primary" icon="bi-file-earmark-text" /></div>
        <div class="col-xl-3 col-md-6"><x-metric-card label="Phát hiện đang mở" :value="(string) $site['findings']"
                note="Vấn đề nghiêm trọng cần xem xét" tone="warning" icon="bi-exclamation-diamond" /></div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title"><i class="bi bi-stars text-primary me-2"></i>Nhận định tổng hợp từ AI</h2>
                <p class="mg-card-subtitle">AI đọc điểm số, độ phủ, finding, độ tin cậy, URL và bằng chứng của lượt quét gần nhất.</p>
            </div>
            @if ($aiAssessedAt)
                <div class="card-toolbar text-muted fs-8">Cập nhật {{ $aiAssessedAt->diffForHumans() }}</div>
            @endif
        </div>
        <div class="card-body pt-1">
            @if ($aiAssessment)
                <div class="d-flex align-items-start justify-content-between gap-4 flex-wrap mb-5">
                    <div>
                        <h3 class="fs-4 mb-2">{{ $aiAssessment['headline'] ?? 'Đánh giá tình trạng website' }}</h3>
                        <p class="text-gray-700 mb-0">{{ $aiAssessment['summary'] ?? '' }}</p>
                    </div>
                    <x-status-badge :status="$aiAssessment['risk_level'] ?? $site['status']" />
                </div>

                @if (!empty($aiAssessment['key_issues']))
                    <div class="row g-4 mb-5">
                        @foreach ($aiAssessment['key_issues'] as $issue)
                            <div class="col-lg-6">
                                <div class="border rounded p-4 h-100">
                                    <div class="d-flex justify-content-between gap-3 mb-3">
                                        <strong>{{ $issue['title'] ?? 'Vấn đề cần xem xét' }}</strong>
                                        <x-status-badge :status="$issue['severity'] ?? 'review'" />
                                    </div>
                                    <p class="mb-2 text-gray-700">{{ $issue['why_it_matters'] ?? '' }}</p>
                                    @if (!empty($issue['evidence']))
                                        <p class="mb-2 fs-8"><strong>Căn cứ:</strong> {{ $issue['evidence'] }}</p>
                                    @endif
                                    @if (!empty($issue['affected_urls']))
                                        <div class="mb-2 fs-8"><strong>URL liên quan:</strong>
                                            @foreach ($issue['affected_urls'] as $url)
                                                <a class="d-block text-break mt-1" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if (!empty($issue['evidence_quotes']))
                                        @foreach ($issue['evidence_quotes'] as $quote)
                                            <blockquote class="border-start border-3 border-warning ps-3 mt-2 mb-2 fs-8">{{ $quote }}</blockquote>
                                        @endforeach
                                    @endif
                                    @if (!empty($issue['source_urls']))
                                        <div class="mb-2 fs-8"><strong>Nguồn/tài nguyên đối chiếu:</strong>
                                            @foreach ($issue['source_urls'] as $url)
                                                <a class="d-block text-break mt-1" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }}</a>
                                            @endforeach
                                        </div>
                                    @endif
                                    <p class="mb-0 fs-8"><strong>Khắc phục:</strong> {{ $issue['recommendation'] ?? '' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (!empty($aiAssessment['priorities']))
                    <h3 class="fs-6 mb-3">Thứ tự xử lý đề xuất</h3>
                    <ol class="mb-0 ps-5">
                        @foreach ($aiAssessment['priorities'] as $priority)
                            <li class="mb-2">{{ $priority }}</li>
                        @endforeach
                    </ol>
                @endif
                @if (!empty($aiAssessment['limitations']))
                    <div class="alert alert-light mt-5 mb-0"><strong>Giới hạn đánh giá:</strong>
                        {{ implode(' ', $aiAssessment['limitations']) }}
                    </div>
                @endif

                @if (!empty($aiEvidenceExamples))
                    <hr class="my-6">
                    <h3 class="fs-6 mb-2">URL và căn cứ tiêu biểu từ dữ liệu quét</h3>
                    <p class="text-muted fs-8 mb-4">Danh sách này lấy trực tiếp từ finding, không phải URL do AI tự suy đoán.</p>
                    @foreach ($aiEvidenceExamples as $example)
                        <div class="border rounded p-4 mb-3">
                            <div class="d-flex justify-content-between gap-3 mb-2">
                                <a href="{{ route('findings.show', $example['finding_id']) }}"><strong>{{ $example['title'] }}</strong></a>
                                <x-status-badge :status="$example['severity']" />
                            </div>
                            <a class="d-block text-break fs-8 mb-2" href="{{ $example['url'] }}" target="_blank" rel="noopener noreferrer">{{ $example['url'] }}</a>
                            @foreach ($example['quotes'] as $quote)
                                <blockquote class="border-start border-3 border-warning ps-3 my-2 fs-8">{{ $quote }}</blockquote>
                            @endforeach
                            @if ($example['matched_url'])
                                <div class="bg-light-danger rounded p-3 mt-3 fs-8">
                                    <strong>Trùng {{ $example['similarity'] !== null ? $example['similarity'].'%' : '' }} với:</strong>
                                    <a class="d-block text-break mt-1" href="{{ $example['matched_url'] }}" target="_blank" rel="noopener noreferrer">{{ $example['matched_url'] }}</a>
                                </div>
                            @endif
                            @if (!empty($example['source_urls']))
                                <div class="bg-light rounded p-3 mt-3 fs-8">
                                    <strong>URL nguồn/tài nguyên cần đối chiếu:</strong>
                                    @foreach ($example['source_urls'] as $sourceUrl)
                                        <a class="d-block text-break mt-1" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceUrl }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                @endif
            @else
                <div class="text-center py-7">
                    <i class="bi bi-stars fs-1 text-primary"></i>
                    <p class="mt-3 mb-4 text-muted">
                        {{ $aiAssessmentScan ? 'Chưa có nhận định AI cho lượt quét gần nhất.' : 'Hãy quét website trước để AI có dữ liệu đánh giá.' }}
                    </p>
                    @if ($aiReady && $aiAssessmentScan)
                        <form method="POST" action="{{ route('sites.ai-assessment', $site['slug']) }}">@csrf
                            <button class="btn btn-primary"><i class="bi bi-stars me-2"></i>Đánh giá bằng AI</button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Phân tích tình trạng chính sách</h2>
                <p class="mg-card-subtitle">Điểm số kết hợp bằng chứng trang, mức độ nghiêm trọng và độ tin cậy của từng phát hiện.</p>
            </div>
        </div>
        <div class="card-body pt-1">
            <div class="row g-4">
                @foreach ($site['policies'] as $policy)
                    <div class="col-md-6 col-xxl-3">
                        <div class="mg-policy-card">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-5">
                                <strong>{{ $policy['name'] }}</strong><x-status-badge :status="$policy['status']" />
                            </div>
                            <div class="d-flex align-items-baseline justify-content-between">
                                <div><span
                                        class="mg-policy-score mg-score-{{ $policy['status'] }}">{{ $policy['score'] }}</span><small
                                        class="text-muted">/100</small></div><span
                                    class="text-muted fs-7">{{ $policy['count'] }}</span>
                            </div>
                            <div class="progress h-6px mt-4">
                                <div class="progress-bar mg-progress-{{ $policy['status'] }}"
                                    style="width: {{ $policy['score'] }}%"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card mg-card mb-5">
{{--        <div class="card-header border-0 pt-2"><div class="card-title d-block">--}}
{{--            <h2 class="mg-card-title">Lưu lượng GA4 · 7 ngày gần nhất</h2>--}}
{{--            <p class="mg-card-subtitle">Lượt quét ưu tiên theo thứ tự lưu lượng từ cao xuống thấp.</p>--}}
{{--        </div></div>--}}
        <div class="card-body pt-0">
            @if (!$ga4)
                <a class="btn btn-light-primary" href="{{ route('ga4.connect', $site['slug']) }}"><i class="bi bi-google me-2"></i>Kết nối Google Analytics</a>
            @else
                <form class="row g-3 align-items-end mb-5" method="POST" action="{{ route('ga4.update', $site['slug']) }}">
                    @csrf @method('PATCH')
                    <div class="col-md-5"><label class="form-label">Mã thuộc tính GA4</label>
                        <input class="form-control" name="property_id" value="{{ $ga4->property_id }}" placeholder="123456789" required>
                    </div>
                    <div class="col-auto"><button class="btn btn-light-primary">Lưu thuộc tính</button></div>
                </form>
                @if ($ga4->property_id)
                    <form method="POST" action="{{ route('ga4.sync', $site['slug']) }}" class="mb-5">@csrf
                        <button class="btn btn-primary">Đồng bộ lưu lượng ngay</button>
                        <span class="text-muted ms-3">Đồng bộ lần cuối: {{ $ga4->last_synced_at?->diffForHumans() ?? 'chưa bao giờ' }}</span>
                    </form>
                @endif
                @foreach($trafficPages as $page)
                    <div class="d-flex justify-content-between border-bottom py-2 gap-4">
                        <span class="text-truncate" title="{{ $page->url }}">{{ $page->url }}</span>
                        <strong>{{ number_format($page->ga4_views_7d) }} lượt xem</strong>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    <div class="card mg-card">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">URL có rủi ro cao nhất</h2>
                <p class="mg-card-subtitle">Ưu tiên các trang có khả năng khiến tài khoản bị xử lý cao nhất.</p>
            </div>
            <div class="card-toolbar"><a href="{{ route('findings.index') }}" class="btn btn-light-primary btn-sm">Xem tất cả phát hiện</a></div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>URL</th>
                            <th>Vấn đề chính</th>
                            <th>Mức độ</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($site['risky_urls'] as $url)
                            <tr>
                                <td class="mw-350px"><span
                                        class="d-block text-truncate fw-semibold">{{ $url['path'] }}</span></td>
                                <td class="text-gray-700">{{ $url['issue'] }}</td>
                                <td><x-status-badge :status="$url['severity']" /></td>
                                <td class="text-end"><a href="{{ route('findings.show', $url['finding_id']) }}"
                                        class="btn btn-sm btn-light-primary">Xem bằng chứng</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
