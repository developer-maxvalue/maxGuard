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
    </div>

    <div class="card mg-card mb-5">
        <div class="card-body p-5">
            <div class="d-flex align-items-start gap-3">
                <i class="bi bi-link-45deg fs-3 text-primary"></i>
                <div class="min-w-0">
                    <div class="mg-eyebrow mb-2">{{ $finding['page_id'] ? 'URL bị ảnh hưởng' : 'Phạm vi toàn website' }}</div>
                    <a class="d-block text-break fw-semibold" href="{{ $finding['url'] }}" target="_blank" rel="noopener noreferrer">
                        {{ $finding['url'] }}
                    </a>
                </div>
            </div>
        </div>
    </div>

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
                        <div><small>Đối chiếu chính sách</small><strong>{{ $finding['policy'] }}</strong>
                            <a class="d-inline-flex align-items-center gap-1 mt-2 fs-8" href="{{ $finding['policy_url'] }}"
                                target="_blank" rel="noopener noreferrer">Xem chính sách chính thức của Google <i class="bi bi-box-arrow-up-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
@endsection
