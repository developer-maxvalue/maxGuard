@extends('layouts.app')

@section('title', 'Trung tâm quét')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Trung tâm quét</h1>
            <p>Chạy kiểm tra tuân thủ toàn website hoặc theo mục tiêu mà không làm gián đoạn yêu cầu web.</p>
        </div>
    </div>

    @error('queue')
        <div class="alert alert-danger d-flex align-items-start gap-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-3"></i>
            <div><strong class="d-block mb-1">Không thể đưa lượt quét vào hàng đợi</strong>{{ $message }}</div>
        </div>
    @enderror

    <div class="row g-5">
        <div class="col-xl-7">
            <div class="card mg-card">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Bắt đầu quét tuân thủ</h2>
                        <p class="mg-card-subtitle">Mỗi yêu cầu được chuyển đến hàng đợi quét đã cấu hình.</p>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <form method="POST" action="{{ route('scans.store') }}">
                        @csrf
                        <div class="mb-6"><label class="form-label fw-semibold">Website</label><select name="site"
                                class="form-select form-select-solid @error('site') is-invalid @enderror">
                                <option value="">Chọn website</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site['domain'] }}" @selected(old('site') === $site['domain'])>{{ $site['domain'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('site')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-7"><label class="form-label fw-semibold">Loại quét</label>
                            <div class="mg-scan-options">
                                @foreach ([['full', 'Tuân thủ toàn diện', 'Bản quyền, chất lượng nội dung, quảng cáo, độ tin cậy và quyền riêng tư', 'bi-shield-check'], ['copyright', 'Bản quyền và trùng lặp', 'Độ tương đồng văn bản, nguồn gốc hình ảnh và nội dung đa phương tiện', 'bi-c-circle'], ['ads', 'Trải nghiệm quảng cáo', 'Mật độ, vị trí và nguy cơ nhấp nhầm', 'bi-badge-ad'], ['privacy', 'Quyền riêng tư và đồng ý', 'Kiểm tra CMP, chế độ đồng ý và thông báo', 'bi-fingerprint']] as $type)
                                    <label><input type="radio" name="scan_type" value="{{ $type[0] }}"
                                            @checked(old('scan_type', 'full') === $type[0])><span><i
                                                class="bi {{ $type[3] }}"></i><strong>{{ $type[1] }}</strong><small>{{ $type[2] }}</small></span></label>
                                @endforeach
                            </div>
                        </div>
                        <div class="row g-5 mb-7">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="max-urls">Số bài viết mới tối đa</label>
                                <input id="max-urls" type="number" name="max_urls" min="1"
                                    max="{{ $maxUrlSafetyLimit }}" value="{{ old('max_urls') }}" placeholder="Tất cả bài viết"
                                    class="form-control form-control-solid @error('max_urls') is-invalid @enderror">
                                <div class="form-text">Khi đặt giới hạn, MaxGuard đọc mọi sitemap và chọn bài viết mới nhất theo
                                    <code>&lt;lastmod&gt;</code>. Để trống để quét tất cả URL được phát hiện.
                                </div>
                                @error('max_urls')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold d-block">Phân tích chính sách bằng AI</label>
                                <label class="form-check form-switch form-check-custom form-check-solid mt-3">
                                    <input class="form-check-input" type="checkbox" name="use_ai" value="1"
                                        @checked((bool) old('use_ai', $aiInfo['ready'])) @disabled(!$aiInfo['ready'])>
                                    <span class="form-check-label fw-semibold">Phân tích ý nghĩa trang bằng
                                        {{ $aiInfo['model'] }}</span>
                                </label>
                                @if ($aiInfo['ready'])
                                    <div class="form-text">Giới hạn an toàn AI:
                                        {{ $aiInfo['page_limit'] === 0 ? 'tất cả trang đã thu thập' : number_format($aiInfo['page_limit']) . ' trang mỗi lượt quét' }}.
                                    </div>
                                @else
                                    <div class="form-text text-warning">AI chưa sẵn sàng.
                                        @if(auth()->user()?->is_admin)
                                            <a href="{{ route('admin.ai-settings.index') }}">Mở Cài đặt AI</a> để chọn nhà cung cấp và cấu hình kết nối.
                                        @else
                                            Vui lòng liên hệ quản trị viên hệ thống.
                                        @endif
                                    </div>
                                @endif
                                @error('use_ai')
                                    <div class="text-danger fs-8 mt-2">{{ $message }}</div>
                                @enderror
                                <label class="form-check form-check-custom form-check-solid mt-4">
                                    <input class="form-check-input" type="checkbox" name="force_rescan" value="1"
                                        @checked((bool) old('force_rescan', false))>
                                    <span class="form-check-label">Buộc phân tích lại URL không thay đổi</span>
                                </label>
                                <div class="form-text">Tắt tùy chọn này để tái sử dụng kết quả tương thích cho nội dung không thay đổi.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end"><button class="btn btn-primary px-6"><i
                                    class="bi bi-play-fill me-2"></i>Đưa vào hàng đợi</button></div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card mg-card mb-5">
                <div class="card-body p-6">
                    <div class="d-flex align-items-center justify-content-between mb-5">
                        <div>
                            <div class="mg-eyebrow mb-2">Hoạt động hàng đợi</div>
                            <h2 class="mg-card-title mb-0">{{ $scanStats['running'] }} lượt quét đang chạy</h2>
                        </div><span class="mg-icon-box mg-icon-box-success"><i class="bi bi-cpu"></i></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted fs-7 mb-2"><span>Mức sử dụng ước tính</span><strong class="text-gray-800">{{ $scanStats['utilization'] }}%</strong></div>
                    <div class="progress h-8px">
                        <div class="progress-bar bg-primary" style="width:{{ $scanStats['utilization'] }}%"></div>
                    </div>
                    <div class="row g-3 mt-4">
                        <div class="col-6">
                            <div class="mg-mini-stat"><strong>{{ $scanStats['target_running'] }}</strong><span>URL trong các lô đang chạy</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mg-mini-stat"><strong>{{ $scanStats['target_queued'] }}</strong><span>URL đang chờ</span></div>
                        </div>
                    </div>
{{--                    <div class="mt-5 p-4 rounded bg-light-primary">--}}
{{--                        @if ($queueInfo['driver'] === 'sync')--}}
{{--                            <div class="mg-eyebrow mb-2">Synchronous queue</div>--}}
{{--                            <span class="d-block fs-8 text-muted">No worker is required, but long scans can time out.--}}
{{--                                Database or Redis queue is recommended.</span>--}}
{{--                        @else--}}
{{--                            <div class="mg-eyebrow mb-2">1 control worker</div>--}}
{{--                            <code class="d-block text-break">{{ $queueInfo['control_worker_command'] }}</code>--}}
{{--                            <div class="mg-eyebrow mb-2 mt-4">{{ $queueInfo['page_workers'] }} parallel page workers</div>--}}
{{--                            <code class="d-block text-break">{{ $queueInfo['page_worker_command'] }}</code>--}}
{{--                            <span class="d-block fs-9 text-muted mt-2">Run the page command in--}}
{{--                                {{ $queueInfo['page_workers'] }} processes, preferably with Supervisor.</span>--}}
{{--                        @endif--}}
{{--                    </div>--}}
                </div>
            </div>
            <div class="card mg-card">
                <div class="card-header border-0 pt-2">
                    <div class="card-title">
                        <h2 class="mg-card-title">Thiết lập quét an toàn mặc định</h2>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <ul class="mg-check-list">
                        <li><i class="bi bi-check2"></i>Giới hạn tốc độ theo máy chủ</li>
                        <li><i class="bi bi-check2"></i>Tuân thủ quy tắc robots.txt</li>
                        <li><i class="bi bi-check2"></i>Lưu bằng chứng bất biến</li>
                        <li><i class="bi bi-check2"></i>Thử lại lỗi tạm thời</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mg-card mt-5">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Lượt quét gần đây</h2>
                <p class="mg-card-subtitle">Trạng thái hàng đợi và tiến trình xử lý từ cơ sở dữ liệu.</p>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr>
                            <th>Website</th>
                            <th>Loại</th>
                            <th>Trạng thái</th>
                            <th>Tiến độ</th>
                            <th>URL đã kiểm tra / tổng số</th>
                            <th>Giới hạn và AI</th>
                            <th>Phát hiện</th>
                            <th>Bắt đầu</th>
                        </tr>
                    </thead>
                    <tbody id="mg-live-scans-body">
                        @forelse($recentScans as $scan)
                            <tr>
                                <td><a class="fw-semibold" href="{{ route('scans.show', $scan) }}">{{ $scan->website->domain }}</a>
                                    <a class="d-block fs-9 text-primary mt-1" href="{{ route('scans.show', $scan) }}">Xem chi tiết {{ number_format($scan->pages_discovered) }} URL →</a>
                                </td>
                                <td>{{ ['full' => 'Toàn diện', 'priority' => 'Ưu tiên', 'copyright' => 'Bản quyền', 'ads' => 'Quảng cáo', 'privacy' => 'Quyền riêng tư'][$scan->type] ?? $scan->type }}</td>
                                <td><x-status-badge :status="$scan->status" />
                                    @if (data_get($scan->meta, 'is_sampled', false))
                                        <span class="badge badge-light-info mt-1">Mẫu mới nhất</span>
                                    @endif
                                    @if ($scan->error_message)
                                        <span class="d-block text-danger fs-9 mt-1 text-truncate mw-200px"
                                            title="{{ $scan->error_message }}">{{ $scan->error_message }}</span>
                                    @endif
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="progress h-6px w-100px">
                                            <div class="progress-bar bg-primary" style="width:{{ $scan->progress }}%">
                                            </div>
                                        </div><span>{{ $scan->progress }}%</span>
                                    </div>
                                </td>
                                <td><strong>{{ number_format($scan->pages_scanned) }} /
                                        {{ number_format($scan->pages_discovered) }}</strong>
                                    <span class="d-block text-muted fs-9">đã kiểm tra / đã chọn</span>
                                    <span
                                        class="d-block text-muted fs-9 mt-1">{{ number_format(max(0, $scan->pages_scanned - $scan->pages_skipped_unchanged)) }}
                                        đã phân tích</span>
                                    @if ($scan->pages_skipped_unchanged > 0)
                                        <span
                                            class="d-block text-success fs-9 mt-1">{{ number_format($scan->pages_skipped_unchanged) }}
                                            không thay đổi · bỏ qua phân tích</span>
                                    @endif
                                    @if (data_get($scan->meta, 'is_sampled', false))
                                        <span class="d-block text-info fs-9 mt-1">Đã chọn
                                            {{ number_format($scan->pages_discovered) }} of
                                            {{ number_format(data_get($scan->meta, 'available_urls', $scan->pages_discovered)) }}
                                            bài mới nhất theo lastmod</span>
                                    @endif
                                    @if ($scan->meta)
                                        <span
                                            class="d-block text-muted fs-9 mt-1">{{ data_get($scan->meta, 'sitemap_files_processed', 0) }}
                                            sitemap ·
                                            {{ data_get($scan->meta, 'failed_requests', 0) }} thất bại ·
                                            {{ data_get($scan->meta, 'blocked_by_robots', 0) }} bị chặn</span>
                                    @endif
                                    @if (data_get($scan->meta, 'parallel_scan', false))
                                        <span class="d-block text-primary fs-9 mt-1">
                                            {{ number_format(data_get($scan->meta, 'batches_completed', 0)) }} /
                                            {{ number_format(data_get($scan->meta, 'batches_total', 0)) }} lô ·
                                            {{ number_format($scan->targets_running_count ?? data_get($scan->meta, 'targets_running', 0)) }} URL đang chạy ·
                                            {{ number_format($scan->targets_queued_count ?? data_get($scan->meta, 'targets_queued', 0)) }} đang chờ
                                        </span>
                                        @if (($scan->targets_failed_count ?? data_get($scan->meta, 'targets_failed', 0)) > 0)
                                            <span class="d-block text-danger fs-9 mt-1">
                                                {{ number_format($scan->targets_failed_count ?? data_get($scan->meta, 'targets_failed', 0)) }} tác vụ URL thất bại
                                            </span>
                                        @endif
                                    @endif
                                    @if ($scan->current_url)
                                        <span class="d-block text-primary fs-9 mt-1 text-truncate mw-250px"
                                            title="{{ $scan->current_url }}">Hiện tại: {{ $scan->current_url }}</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $scan->max_urls ? number_format($scan->max_urls) : 'Toàn bộ' }}</strong><span
                                        class="d-block text-muted fs-9">{{ data_get($scan->meta, 'is_sampled', false) ? 'mẫu bài mới nhất' : 'giới hạn URL' }}</span>
                                    @if ($scan->use_ai)
                                        <span class="badge badge-light-info mt-2"><i class="bi bi-stars me-1"></i>AI
                                            {{ number_format($scan->ai_pages_analyzed) }} trang</span>
                                        @if (data_get($scan->meta, 'ai_limit_reached', false))
                                            <span class="d-block text-warning fs-9 mt-1">Đã đạt giới hạn an toàn AI</span>
                                        @endif
                                        @if (data_get($scan->meta, 'ai_errors', 0) > 0)
                                            <span
                                                class="d-block text-danger fs-9 mt-1">{{ data_get($scan->meta, 'ai_errors') }}
                                                lỗi AI</span>
                                        @endif
                                    @else
                                        <span class="d-block text-muted fs-9 mt-2">Chỉ dùng quy tắc</span>
                                    @endif
                                    @if ($scan->force_rescan)
                                        <span class="d-block text-warning fs-9 mt-1">Buộc phân tích lại</span>
                                    @endif
                                </td>
                                <td><strong>{{ number_format($scan->findings_count) }}</strong><span
                                        class="d-block text-muted fs-9">phát hiện trong lượt quét này</span>
                                    @if ($scan->ai_findings_count > 0)
                                        <span
                                            class="d-block text-info fs-9 mt-1">{{ number_format($scan->ai_findings_count) }}
                                            từ AI</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ ($scan->started_at ?? $scan->created_at)->diffForHumans() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-10">Chưa có lượt quét nào được đưa vào hàng đợi.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mg-card mt-5" id="live-findings">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Báo cáo phát hiện trực tiếp</h2>
                <p class="mg-card-subtitle">Vấn đề mới xuất hiện ngay khi từng URL được phân tích xong.</p>
            </div>
            <div class="card-toolbar d-flex gap-2">
                <span class="badge badge-light-success"><span class="mg-pulse-dot me-1"></span>Trực tiếp</span>
                <a href="{{ route('findings.export.xlsx') }}" class="btn btn-sm btn-light-success"><i
                        class="bi bi-file-earmark-excel me-2"></i>Xuất Excel</a>
                <a href="{{ route('findings.index') }}" class="btn btn-sm btn-light-primary">Tất cả phát hiện</a>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr>
                            <th>Website / URL</th>
                            <th>Vấn đề</th>
                            <th>Bộ phân tích</th>
                            <th>Mức độ</th>
                            <th>Độ tin cậy</th>
                            <th>Thời điểm phát hiện</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mg-live-findings-body">
                        @forelse($liveFindings as $finding)
                            <tr>
                                <td><strong>{{ $finding->website->domain }}</strong><span
                                        class="d-block text-muted fs-9 text-truncate mw-300px"
                                        title="{{ $finding->page?->url ?? $finding->website->start_url }}">{{ $finding->page?->url ?? $finding->website->start_url }}</span>
                                </td>
                                <td><strong>{{ \App\Support\UiText::text($finding->title) }}</strong><span
                                        class="d-block text-muted fs-9">{{ \App\Support\UiText::label($finding->category) }}</span></td>
                                <td><span
                                        class="badge {{ str_starts_with($finding->rule_key, 'ai.') ? 'badge-light-info' : 'badge-light' }}">{{ str_starts_with($finding->rule_key, 'ai.') ? 'AI' : 'Quy tắc' }}</span>
                                </td>
                                <td><x-status-badge :status="$finding->severity" /></td>
                                <td><strong>{{ $finding->confidence }}%</strong></td>
                                <td class="text-muted">{{ $finding->last_seen_at->diffForHumans() }}</td>
                                <td class="text-end"><a href="{{ route('findings.show', $finding) }}"
                                        class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-10">Chưa phát hiện vấn đề nào. Bảng này tự cập nhật trong khi quét.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('page-scripts')
    <script>
        window.MaxGuardLive = {
            endpoint: @json(route('scans.live')),
            pollMs: 4000
        };
    </script>
@endpush
