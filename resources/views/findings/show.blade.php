@extends('layouts.app')

@section('title', $finding['id'])

@section('content')
    <div class="mg-breadcrumb">
        <a href="{{ route('findings.index') }}">Phát hiện</a><i
            class="bi bi-chevron-right"></i><span>{{ $finding['id'] }}</span>
    </div>
    <div class="mg-page-heading align-items-end">
        <div>
            <div class="d-flex align-items-center flex-wrap gap-3">
                <h1 class="mb-0">{{ $finding['title'] }}</h1><x-status-badge :status="$finding['severity']" />
            </div>
            <p class="mt-2 mb-0">{{ $finding['site'] }} · Phát hiện {{ $finding['detected'] }} · Độ tin cậy
                {{ $finding['confidence'] }}%</p>
        </div>
        <div class="d-flex gap-3">
            <form method="POST" action="{{ route('findings.update', $finding['id']) }}">@csrf @method('PATCH')<input
                    type="hidden" name="status" value="investigating"><button class="btn btn-light"><i
                        class="bi bi-search me-2"></i>Điều tra</button></form>
            <form method="POST" action="{{ route('findings.update', $finding['id']) }}">@csrf @method('PATCH')<input
                    type="hidden" name="status" value="remediating"><button class="btn btn-primary"><i
                        class="bi bi-check2-circle me-2"></i>Bắt đầu khắc phục</button></form>
            <form method="POST" action="{{ route('findings.update', $finding['id']) }}">@csrf @method('PATCH')<input
                    type="hidden" name="status" value="resolved"><button class="btn btn-success"><i
                        class="bi bi-shield-check me-2"></i>Đánh dấu đã xử lý</button></form>
        </div>
    </div>

    @if($finding['page_id'])
        @php($review = $finding['copyright_review'])
        <div class="card mg-card mb-5"><div class="card-body p-6">
            <h2 class="mg-card-title">Kiểm tra bản quyền thủ công trên Google</h2>
            <p class="text-muted">Tìm chính xác tiêu đề trang, kiểm tra các nhà xuất bản trùng khớp rồi lưu kết luận.</p>
            <a class="btn btn-light-primary mb-4" target="_blank" rel="noopener noreferrer"
               href="https://www.google.com/search?q={{ urlencode('"' . ($finding['page_title'] ?: $finding['url']) . '"') }}">
                <i class="bi bi-google me-2"></i>Tìm chính xác tiêu đề
            </a>
            <form method="POST" action="{{ route('copyright-reviews.update', $finding['page_id']) }}" class="row g-3">
                @csrf @method('PATCH')
                <div class="col-md-3"><select name="status" class="form-select">
                    @foreach(['pending' => 'Đang chờ', 'clear' => 'Không vi phạm', 'suspected' => 'Nghi ngờ', 'confirmed' => 'Đã xác nhận vi phạm'] as $value => $label)
                        <option value="{{ $value }}" @selected(($review?->status ?? 'pending') === $value)>{{ $label }}</option>
                    @endforeach
                </select></div>
                <div class="col-md-4"><input type="url" class="form-control" name="matched_url" value="{{ $review?->matched_url }}" placeholder="URL nguồn trùng khớp"></div>
                <div class="col-md-4"><input class="form-control" name="notes" value="{{ $review?->notes }}" placeholder="Ghi chú kiểm tra"></div>
                <div class="col-md-1"><button class="btn btn-primary">Lưu</button></div>
            </form>
            @if ($finding['is_copyright'])
                <hr class="my-5">
                <h3 class="fs-6 mb-3">URL nguồn hoặc tài nguyên cần đối chiếu</h3>
                @forelse ($finding['copyright_source_urls'] as $sourceUrl)
                    <div class="d-flex align-items-start gap-3 border rounded p-3 mb-2">
                        <i class="bi bi-link-45deg text-primary fs-4"></i>
                        <a class="text-break" href="{{ $sourceUrl }}" target="_blank" rel="noopener noreferrer">{{ $sourceUrl }}</a>
                    </div>
                @empty
                    <div class="alert alert-warning mb-0">
                        Chưa xác định được URL bài viết hoặc tài nguyên gốc. Finding này mới là tín hiệu cần xác minh, chưa phải bằng chứng rằng nội dung đã sao chép từ một nguồn cụ thể.
                    </div>
                @endforelse
                @if (!empty($finding['copyright_source_urls']))
                    <p class="text-muted fs-8 mt-3 mb-0">URL ảnh/CDN bên ngoài chỉ chứng minh tài nguyên được tải từ domain khác; không tự động chứng minh vi phạm bản quyền. URL bài gốc chỉ được coi là nguồn đối chiếu sau khi kiểm tra và lưu kết luận ở biểu mẫu phía trên.</p>
                @endif
            @endif
        </div></div>
    @endif

    <div class="row g-5">
        <div class="col-xl-8">
            @if (!empty($finding['evidence_quotes']))
                <div class="card mg-card mb-5">
                    <div class="card-header border-0 pt-2">
                        <div class="card-title d-block">
                            <h2 class="mg-card-title">Đoạn văn bị phát hiện</h2>
                            <p class="mg-card-subtitle">Trích nguyên văn từ nội dung trang mà bộ phân tích đã dùng làm căn cứ.</p>
                        </div>
                    </div>
                    <div class="card-body pt-1">
                        @foreach ($finding['evidence_quotes'] as $quote)
                            <blockquote class="border-start border-4 border-warning bg-light-warning rounded-end p-4 mb-3">
                                <i class="bi bi-quote fs-3 text-warning me-2"></i><span>{{ $quote }}</span>
                            </blockquote>
                        @endforeach
                        <p class="text-muted fs-8 mb-0">Vị trí: nội dung văn bản được trích xuất từ URL phía trên. Hãy mở URL gốc để đối chiếu ngữ cảnh đầy đủ.</p>
                    </div>
                </div>
            @endif

            @if (!empty($finding['duplicate_matches']))
                <div class="card mg-card mb-5">
                    <div class="card-header border-0 pt-2">
                        <div class="card-title d-block">
                            <h2 class="mg-card-title">Các URL có nội dung trùng lặp</h2>
                            <p class="mg-card-subtitle">Cặp URL và mức tương đồng được detector ghi nhận trong chính lượt quét.</p>
                        </div>
                    </div>
                    <div class="card-body pt-1">
                        @foreach ($finding['duplicate_matches'] as $match)
                            <div class="border rounded p-4 mb-3">
                                <div class="mb-3"><small class="text-muted d-block mb-1">URL bị gắn cờ</small>
                                    <a class="text-break" href="{{ $match['source_url'] }}" target="_blank" rel="noopener noreferrer">{{ $match['source_url'] }}</a>
                                </div>
                                <div class="mb-3"><small class="text-muted d-block mb-1">Trùng với URL</small>
                                    <a class="text-break" href="{{ $match['matched_url'] }}" target="_blank" rel="noopener noreferrer">{{ $match['matched_url'] }}</a>
                                </div>
                                <div class="d-flex gap-3 align-items-center flex-wrap">
                                    @if ($match['similarity'] !== null)
                                        <span class="badge badge-light-danger">Tương đồng {{ $match['similarity'] }}%</span>
                                    @endif
                                    @if ($match['method'])
                                        <span class="text-muted fs-8">Phương pháp: {{ $match['method'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                        @if (empty($finding['evidence_quotes']))
                            <div class="alert alert-light mb-0">Lượt quét này đã lưu chữ ký tương đồng và cặp URL nhưng chưa lưu cụm từ trùng khớp. Hãy quét lại để thu thập thêm các cụm văn bản đối chiếu.</div>
                        @endif
                    </div>
                </div>
            @endif

        </div>

        <div class="col-xl-4">
            <div class="card mg-card mb-5">
                <div class="card-body p-6">
                    <div class="d-flex align-items-start justify-content-between mb-4">
                        <div>
                            <div class="mg-eyebrow mb-2">Đánh giá rủi ro</div>
                            <h2 class="mg-card-title mb-0">Lý do bị gắn cờ</h2>
                        </div><span class="mg-confidence">{{ $finding['confidence'] }}%</span>
                    </div>
                    <p class="text-gray-700 lh-lg">{{ $finding['summary'] }}</p>
                    <div class="mg-policy-callout"><i class="bi bi-journal-text"></i>
                        <div><small>Đối chiếu chính sách</small><strong>{{ $finding['policy'] }}</strong></div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
