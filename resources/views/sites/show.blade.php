@extends('layouts.app')

@section('title', $site['domain'])

@section('content')
    @php($aiAssessmentBusy = in_array($aiAssessmentStatus, ['queued', 'running', 'retrying'], true))
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
            @if ($activeScan)
                <div class="mt-3 p-3 rounded bg-light-primary fs-8" id="scan-debug"
                    data-endpoint="{{ route('scans.targets.live', $activeScan) }}">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="spinner-border spinner-border-sm text-primary" aria-hidden="true"></span>
                        <strong>Debug đang quét:</strong>
                        <span data-scan-status>{{ \App\Support\UiText::label($activeScan->status) }}</span>
                        <span>·</span><span data-scan-progress>{{ $activeScan->progress }}%</span>
                        <span>·</span><span data-scan-pages>{{ number_format($activeScan->pages_scanned) }}/{{ number_format($activeScan->pages_discovered) }} URL</span>
                        <a class="ms-auto fw-semibold" href="{{ route('scans.show', $activeScan) }}">Xem debug lượt quét #{{ $activeScan->id }} →</a>
                    </div>
                    <div class="text-muted text-break" data-scan-url>{{ $activeScan->current_url ?: 'Đang chuẩn bị danh sách URL…' }}</div>
                </div>
            @endif
        </div>
        <div class="d-flex gap-3">
            <form method="POST" action="{{ route('sites.ai-assessment', $site['slug']) }}">
                @csrf
                <button class="btn btn-light-primary" @disabled(!$aiReady || !$aiAssessmentScan || $aiAssessmentBusy)
                    title="{{ !$aiReady ? 'AI chưa được cấu hình' : (!$aiAssessmentScan ? 'Cần quét website trước' : 'Đọc lại toàn bộ chỉ số và vấn đề của lượt quét gần nhất') }}">
                    @if ($aiAssessmentBusy)
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>AI đang xử lý
                    @else
                        <i class="bi bi-stars me-2"></i>{{ $aiAssessment ? 'AI đánh giá lại' : 'AI đánh giá' }}
                    @endif
                </button>
            </form>
            <form method="POST" action="{{ route('scans.store') }}">
                @csrf
                <input type="hidden" name="site" value="{{ $site['domain'] }}">
                <input type="hidden" name="scan_type" value="full">
                @if ($aiReady)
                    <input type="hidden" name="use_ai" value="1">
                @endif
                <div class="input-group">
                    <input class="form-control form-control-solid" style="max-width: 150px" type="number" name="max_urls"
                        min="1" max="{{ $maxUrlSafetyLimit }}" value="100" aria-label="Số URL quét">
                    <button class="btn btn-primary"><i
                            class="bi bi-arrow-repeat me-2"></i>Quét lại{{ $aiReady ? ' + AI' : '' }}</button>
                </div>
                <div class="form-check form-check-custom form-check-solid mt-2">
                    <input class="form-check-input" type="checkbox" value="1" name="scan_all_site" id="detail-scan-all">
                    <label class="form-check-label fs-8" for="detail-scan-all">Quét toàn bộ website</label>
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

    <div class="card mg-card mb-5" id="ai-assessment-card"
        @if ($aiAssessmentScan) data-status="{{ $aiAssessmentStatus }}" data-endpoint="{{ route('scans.targets.live', $aiAssessmentScan) }}" @endif>
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Phân tích tình trạng chính sách</h2>
                <p class="mg-card-subtitle">Số URL vi phạm trong từng hạng mục trên tổng số URL đã phân tích.</p>
            </div>
        </div>
        <div class="card-body pt-1">
            @if ($aiAssessmentBusy)
                <div class="alert alert-primary d-flex align-items-center gap-3">
                    <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                    <div><strong>Đánh giá AI đang được xử lý trong hàng đợi.</strong> Trang sẽ tự cập nhật khi hoàn tất.</div>
                </div>
            @elseif ($aiAssessmentStatus === 'failed')
                <div class="alert alert-danger">
                    <strong class="d-block mb-1">Đánh giá AI thất bại sau các lần thử lại.</strong>
                    {{ $aiAssessmentError ?: 'Không có thông tin lỗi từ nhà cung cấp AI.' }}
                </div>
            @endif
            <div class="row g-4">
                @foreach ($site['policies'] as $policy)
                    <div class="col-md-6 col-xxl-3">
                        <div class="mg-policy-card">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-5">
                                <strong>{{ $policy['name'] }}</strong><x-status-badge :status="$policy['status']" />
                            </div>
                            <div class="d-flex align-items-baseline justify-content-between">
                                <div><span class="mg-policy-score mg-score-{{ $policy['status'] }}">{{ number_format($policy['violating_urls']) }}</span><small
                                        class="text-muted"> / {{ number_format($policy['total_urls']) }} URL</small></div>
                            </div>
                            <div class="progress h-6px mt-4">
                                <div class="progress-bar mg-progress-{{ $policy['status'] }}"
                                    style="width: {{ $policy['total_urls'] > 0 ? min(100, round(($policy['violating_urls'] / $policy['total_urls']) * 100)) : 0 }}%"></div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title"><i class="bi bi-stars text-primary me-2"></i>Nhận định tổng hợp từ AI</h2>
                <p class="mg-card-subtitle">AI tổng hợp cấu trúc, nội dung, quảng cáo và tình trạng chính sách trên toàn bộ dữ liệu website đã quét.</p>
            </div>
            @if ($aiAssessedAt)
                <div class="card-toolbar text-muted fs-8">Cập nhật {{ $aiAssessedAt->diffForHumans() }}</div>
            @endif
        </div>
        <div class="card-body pt-1">
            @if ($aiAssessment)
                <div class="d-flex align-items-start justify-content-between gap-4 flex-wrap mb-5">
                    <div>
                        <h3 class="fs-4 mb-0">{{ $aiAssessment['headline'] ?? 'Đánh giá tình trạng website' }}</h3>
                    </div>
                    <x-status-badge :status="$aiAssessment['risk_level'] ?? $site['status']" />
                </div>

                @if (!empty($aiAssessment['key_issues']))
                    <h3 class="fs-5 mb-4">Các dấu hiệu rủi ro đáng chú ý</h3>
                    <div class="d-flex flex-column gap-4 mb-5">
                        @foreach ($aiAssessment['key_issues'] as $index => $issue)
                            <div class="border rounded p-4">
                                <div class="d-flex align-items-start justify-content-between gap-3 mb-3">
                                    <strong class="fs-6">{{ $index + 1 }}. {{ $issue['title'] ?? 'Vấn đề cần xem xét' }}</strong>
                                    <x-status-badge :status="$issue['severity'] ?? 'review'" />
                                </div>
                                @if (!empty($issue['evidence']))
                                    <p class="text-gray-700 mb-2"><strong>Dấu hiệu quan sát được:</strong> {{ $issue['evidence'] }}</p>
                                @endif
                                @if (!empty($issue['why_it_matters']))
                                    <p class="text-gray-700 mb-2"><strong>Nhận định và tác động:</strong> {{ $issue['why_it_matters'] }}</p>
                                @endif
                                @if (!empty($issue['example_urls']))
                                    <div class="mb-2">
                                        <strong class="d-block mb-1">URL ví dụ:</strong>
                                        @foreach (array_slice((array) $issue['example_urls'], 0, 2) as $exampleUrl)
                                            <a class="d-block text-break" href="{{ $exampleUrl }}" target="_blank" rel="noopener noreferrer">
                                                {{ $exampleUrl }} <i class="bi bi-box-arrow-up-right ms-1"></i>
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                                @if (!empty($issue['category']))
                                    <p class="mb-2">
                                        <strong>Danh mục trong Báo cáo phát hiện:</strong>
                                        <a href="{{ route('sites.show', $site['slug']) }}?finding_category={{ urlencode($issue['category']) }}#finding-report-filter">
                                            {{ \App\Support\UiText::label($issue['category']) }} ({{ $issue['category'] }})
                                        </a>
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (!empty($aiAssessment['content_overview']))
                    <div class="border rounded p-4 mb-4">
                        <strong class="d-block mb-2">Tổng quan nội dung và cấu trúc</strong>
                        <p class="mb-0 text-gray-700">{{ $aiAssessment['content_overview'] }}</p>
                        @include('sites.partials.ai-policy-references', ['section' => 'content_overview'])
                    </div>
                @endif
                @if (!empty($aiAssessment['transparency_overview']))
                    <div class="border rounded p-4 mb-4">
                        <strong class="d-block mb-2">Tính trung thực và minh bạch của nhà xuất bản</strong>
                        <p class="mb-0 text-gray-700">{{ $aiAssessment['transparency_overview'] }}</p>
                        @include('sites.partials.ai-policy-references', ['section' => 'transparency_overview'])
                    </div>
                @endif
                @if (!empty($aiAssessment['adsense_requirements_overview']))
                    <div class="border rounded p-4 mb-4">
                        <strong class="d-block mb-2">Đối chiếu yêu cầu AdSense</strong>
                        <p class="mb-0 text-gray-700">{{ $aiAssessment['adsense_requirements_overview'] }}</p>
                        @include('sites.partials.ai-policy-references', ['section' => 'adsense_requirements_overview'])
                    </div>
                @endif
                @if (!empty($aiAssessment['policy_overview']))
                    <div class="border rounded p-4 mb-4">
                        <strong class="d-block mb-2">Tổng quan tình trạng chính sách</strong>
                        <p class="mb-0 text-gray-700">{{ $aiAssessment['policy_overview'] }}</p>
                        @include('sites.partials.ai-policy-references', ['section' => 'policy_overview'])
                    </div>
                @endif

                @if (!empty($aiAssessment['no_clear_violation_signals']))
                    <div class="border rounded p-4 mb-4">
                        <strong class="d-block mb-2">Điều không thấy vi phạm rõ ràng</strong>
                        <ul class="mb-0 ps-5 text-gray-700">
                            @foreach ($aiAssessment['no_clear_violation_signals'] as $signal)
                                <li class="mb-2">{{ $signal }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (!empty($aiAssessment['conclusion']) || !empty($aiAssessment['summary']))
                    <div class="border border-primary rounded p-4 mb-5 bg-light-primary">
                        <strong class="d-block mb-2">Kết luận tổng hợp</strong>
                        <p class="mb-0 text-gray-800">{{ $aiAssessment['conclusion'] ?? $aiAssessment['summary'] }}</p>
                    </div>
                @endif

                @if (!empty($aiAssessment['limitations']))
                    <div class="alert alert-light mt-5 mb-0"><strong>Giới hạn đánh giá:</strong>
                        {{ implode(' ', $aiAssessment['limitations']) }}
                    </div>
                @endif

            @else
                <div class="text-center py-7">
                    <i class="bi bi-stars fs-1 text-primary"></i>
                    <p class="mt-3 mb-4 text-muted">
                        {{ $aiAssessmentScan ? 'Chưa có nhận định AI cho lượt quét gần nhất.' : 'Hãy quét website trước để AI có dữ liệu đánh giá.' }}
                    </p>
                    @if ($aiReady && $aiAssessmentScan && !$aiAssessmentBusy)
                        <form method="POST" action="{{ route('sites.ai-assessment', $site['slug']) }}">@csrf
                            <button class="btn btn-primary"><i class="bi bi-stars me-2"></i>Đánh giá bằng AI</button>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </div>

{{--    <div class="card mg-card mb-5">--}}
{{--        <div class="card-header border-0 pt-2"><div class="card-title d-block">--}}
{{--            <h2 class="mg-card-title">Lưu lượng GA4 · 7 ngày gần nhất</h2>--}}
{{--            <p class="mg-card-subtitle">Lượt quét ưu tiên theo thứ tự lưu lượng từ cao xuống thấp.</p>--}}
{{--        </div></div>--}}
{{--        <div class="card-body pt-0">--}}
{{--            @if (!$ga4)--}}
{{--                <a class="btn btn-light-primary" href="{{ route('ga4.connect', $site['slug']) }}"><i class="bi bi-google me-2"></i>Kết nối Google Analytics</a>--}}
{{--            @else--}}
{{--                <form class="row g-3 align-items-end mb-5" method="POST" action="{{ route('ga4.update', $site['slug']) }}">--}}
{{--                    @csrf @method('PATCH')--}}
{{--                    <div class="col-md-5"><label class="form-label">Mã thuộc tính GA4</label>--}}
{{--                        <input class="form-control" name="property_id" value="{{ $ga4->property_id }}" placeholder="123456789" required>--}}
{{--                    </div>--}}
{{--                    <div class="col-auto"><button class="btn btn-light-primary">Lưu thuộc tính</button></div>--}}
{{--                </form>--}}
{{--                @if ($ga4->property_id)--}}
{{--                    <form method="POST" action="{{ route('ga4.sync', $site['slug']) }}" class="mb-5">@csrf--}}
{{--                        <button class="btn btn-primary">Đồng bộ lưu lượng ngay</button>--}}
{{--                        <span class="text-muted ms-3">Đồng bộ lần cuối: {{ $ga4->last_synced_at?->diffForHumans() ?? 'chưa bao giờ' }}</span>--}}
{{--                    </form>--}}
{{--                @endif--}}
{{--                @foreach($trafficPages as $page)--}}
{{--                    <div class="d-flex justify-content-between border-bottom py-2 gap-4">--}}
{{--                        <span class="text-truncate" title="{{ $page->url }}">{{ $page->url }}</span>--}}
{{--                        <strong>{{ number_format($page->ga4_views_7d) }} lượt xem</strong>--}}
{{--                    </div>--}}
{{--                @endforeach--}}
{{--            @endif--}}
{{--        </div>--}}
{{--    </div>--}}

    <div class="card mg-card">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Báo cáo phát hiện</h2>
                <p class="mg-card-subtitle">Danh sách vi phạm của website, có thể lọc theo mức độ và danh mục.</p>
            </div>
        </div>
        <div class="card-body pt-0">
            <form method="GET" class="row g-3 align-items-end mb-5" id="finding-report-filter"
                data-endpoint="{{ route('sites.findings', $site['slug']) }}">
                <div class="col-md-3">
                    <label class="form-label" for="finding-severity">Mức độ</label>
                    <select class="form-select form-select-solid" id="finding-severity" name="finding_severity">
                        <option value="">Tất cả mức độ</option>
                        <option value="critical" @selected(request('finding_severity') === 'critical')>Nghiêm trọng</option>
                        <option value="high" @selected(request('finding_severity') === 'high')>Cao</option>
                        <option value="review" @selected(request('finding_severity') === 'review')>Cần xem xét</option>
                        <option value="info" @selected(request('finding_severity') === 'info')>Thông tin</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="finding-category">Danh mục vi phạm</label>
                    <select class="form-select form-select-solid" id="finding-category" name="finding_category">
                        <option value="">Tất cả danh mục</option>
                        @foreach ($findingCategories as $category => $label)
                            <option value="{{ $category }}" @selected(request('finding_category') === $category)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto"><button class="btn btn-primary" type="submit" data-filter-submit>Lọc báo cáo</button></div>
                <div class="col-auto @if (!request()->filled('finding_severity') && !request()->filled('finding_category')) d-none @endif" data-clear-filter-wrap>
                    <button class="btn btn-light" type="button" data-clear-filter>Xóa lọc</button>
                </div>
                <div class="col-auto d-none" data-filter-loading><span class="spinner-border spinner-border-sm text-primary me-2"></span>Đang tải…</div>
            </form>
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>URL</th>
                            <th>Danh mục</th>
                            <th>Phát hiện</th>
                            <th>Mức độ</th>
                            <th>Độ tin cậy</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="finding-report-body">
                        @forelse ($findingReport as $finding)
                            <tr>
                                <td class="mw-350px"><a class="d-block text-truncate fw-semibold" href="{{ $finding->page?->url ?? $site['start_url'] }}"
                                        target="_blank" rel="noopener noreferrer">{{ $finding->page ? (parse_url($finding->page->url, PHP_URL_PATH) ?: '/') : '/' }}</a></td>
                                <td>{{ \App\Support\UiText::label($finding->category) }}</td>
                                <td class="text-gray-700">{{ \App\Support\UiText::text($finding->title) }}</td>
                                <td><x-status-badge :status="$finding->severity" /></td>
                                <td>{{ $finding->confidence }}%</td>
                                <td class="text-end"><a href="{{ route('findings.show', $finding) }}"
                                        class="btn btn-sm btn-light-primary">Xem bằng chứng</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-7">Không có phát hiện phù hợp với bộ lọc.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-5" id="finding-report-pagination">{{ $findingReport->links() }}</div>
        </div>
    </div>
@endsection

@push('page-scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const scanAll = document.getElementById('detail-scan-all');
            const maxUrls = scanAll?.closest('form')?.querySelector('input[name="max_urls"]');
            if (scanAll && maxUrls) {
                const syncScanLimit = () => maxUrls.disabled = scanAll.checked;
                scanAll.addEventListener('change', syncScanLimit);
                syncScanLimit();
            }

            const debug = document.getElementById('scan-debug');
            if (debug) {
                const refreshDebug = async () => {
                    try {
                        const response = await fetch(debug.dataset.endpoint, {headers: {'Accept': 'application/json'}});
                        if (!response.ok) return;
                        const payload = await response.json();
                        const scan = payload.scan;
                        debug.querySelector('[data-scan-status]').textContent = scan.status === 'running' ? 'Đang chạy' : (scan.status === 'queued' ? 'Đang chờ' : scan.status);
                        debug.querySelector('[data-scan-progress]').textContent = `${scan.progress}%`;
                        debug.querySelector('[data-scan-pages]').textContent = `${scan.pages_scanned}/${scan.pages_discovered} URL`;
                        debug.querySelector('[data-scan-url]').textContent = scan.current_url || 'Đang chuẩn bị danh sách URL…';
                        if (!['queued', 'running'].includes(scan.status)) window.location.reload();
                    } catch (error) {
                        // Giữ dữ liệu gần nhất nếu kết nối tạm thời bị gián đoạn.
                    }
                };
                window.setInterval(refreshDebug, 3000);
            }

            const filter = document.getElementById('finding-report-filter');
            const reportBody = document.getElementById('finding-report-body');
            const pagination = document.getElementById('finding-report-pagination');
            if (!filter || !reportBody || !pagination) return;

            const severity = filter.querySelector('[name="finding_severity"]');
            const category = filter.querySelector('[name="finding_category"]');
            const loading = filter.querySelector('[data-filter-loading]');
            const clearWrap = filter.querySelector('[data-clear-filter-wrap]');
            const submitButton = filter.querySelector('[data-filter-submit]');
            let requestController;

            const element = (tag, className, text) => {
                const node = document.createElement(tag);
                if (className) node.className = className;
                if (text !== undefined) node.textContent = text;
                return node;
            };
            const appendCell = (row, child, className = '') => {
                const cell = element('td', className);
                cell.append(child);
                row.append(cell);
            };
            const renderRows = (items) => {
                reportBody.replaceChildren();
                if (!items.length) {
                    const row = element('tr');
                    const cell = element('td', 'text-center text-muted py-7', 'Không có phát hiện phù hợp với bộ lọc.');
                    cell.colSpan = 6;
                    row.append(cell);
                    reportBody.append(row);
                    return;
                }
                const tones = {critical: 'danger', high: 'warning', review: 'info', info: 'info'};
                items.forEach((item) => {
                    const row = element('tr');
                    const url = element('a', 'd-block text-truncate fw-semibold', item.path);
                    url.href = item.url;
                    url.target = '_blank';
                    url.rel = 'noopener noreferrer';
                    appendCell(row, url, 'mw-350px');
                    appendCell(row, element('span', '', item.category));
                    appendCell(row, element('span', 'text-gray-700', item.title));
                    appendCell(row, element('span', `badge mg-status mg-status-${tones[item.severity] || 'secondary'}`, item.severity_label));
                    appendCell(row, element('span', '', `${item.confidence}%`));
                    const evidence = element('a', 'btn btn-sm btn-light-primary', 'Xem bằng chứng');
                    evidence.href = item.evidence_url;
                    appendCell(row, evidence, 'text-end');
                    reportBody.append(row);
                });
            };
            const renderPagination = (meta) => {
                pagination.replaceChildren();
                if (meta.last_page <= 1) return;
                const wrap = element('div', 'd-flex align-items-center justify-content-between gap-3');
                wrap.append(element('span', 'text-muted fs-8', `Hiển thị ${meta.from || 0}–${meta.to || 0} / ${meta.total}`));
                const buttons = element('div', 'd-flex gap-2');
                [['Trước', meta.current_page - 1], ['Sau', meta.current_page + 1]].forEach(([label, page]) => {
                    const button = element('button', 'btn btn-sm btn-light-primary', label);
                    button.type = 'button';
                    button.dataset.page = page;
                    button.disabled = page < 1 || page > meta.last_page;
                    buttons.append(button);
                });
                wrap.append(buttons);
                pagination.append(wrap);
            };
            const loadFindings = async (page = 1) => {
                requestController?.abort();
                requestController = new AbortController();
                const params = new URLSearchParams({page});
                if (severity.value) params.set('severity', severity.value);
                if (category.value) params.set('category', category.value);
                loading.classList.remove('d-none');
                if (submitButton) submitButton.disabled = true;
                try {
                    const response = await fetch(`${filter.dataset.endpoint}?${params}`, {
                        headers: {'Accept': 'application/json'},
                        signal: requestController.signal,
                    });
                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                    const payload = await response.json();
                    renderRows(payload.data || []);
                    renderPagination(payload.meta);
                    clearWrap.classList.toggle('d-none', !severity.value && !category.value);
                    const browserParams = new URLSearchParams();
                    if (severity.value) browserParams.set('finding_severity', severity.value);
                    if (category.value) browserParams.set('finding_category', category.value);
                    const query = browserParams.toString();
                    window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
                } catch (error) {
                    if (error.name !== 'AbortError') window.alert('Không thể tải báo cáo. Vui lòng thử lại.');
                } finally {
                    loading.classList.add('d-none');
                    if (submitButton) submitButton.disabled = false;
                }
            };

            filter.addEventListener('submit', (event) => {
                event.preventDefault();
                loadFindings();
            });
            filter.querySelector('[data-clear-filter]').addEventListener('click', () => {
                severity.value = '';
                category.value = '';
                loadFindings();
            });
            pagination.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-page], a');
                if (!trigger) return;
                event.preventDefault();
                const page = trigger.dataset.page || new URL(trigger.href).searchParams.get('findings_page') || 1;
                loadFindings(Number(page));
            });
        });
    </script>
@endpush

@push('page-scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const card = document.getElementById('ai-assessment-card');
            if (!card?.dataset.endpoint || !['queued', 'running', 'retrying'].includes(card.dataset.status)) return;

            const poll = window.setInterval(async () => {
                try {
                    const response = await fetch(card.dataset.endpoint, {headers: {'Accept': 'application/json'}});
                    if (!response.ok) return;
                    const payload = await response.json();
                    const status = payload.scan?.ai_assessment_status || '';
                    if (['completed', 'failed'].includes(status)) {
                        window.clearInterval(poll);
                        window.location.reload();
                    }
                } catch (error) {
                    // A later poll can recover from a transient network error.
                }
            }, 5000);
        });
    </script>
@endpush
