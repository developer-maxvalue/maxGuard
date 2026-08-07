@extends('layouts.app')

@section('title', 'Gỡ lỗi URL #'.$target->id)

@section('content')
    <div class="mg-breadcrumb"><a href="{{ route('scans.show', $scan) }}">Lượt quét #{{ $scan->id }}</a><i class="bi bi-chevron-right"></i><span>URL #{{ $target->id }}</span></div>
    <div class="mg-page-heading"><div><h1>Chi tiết xử lý URL</h1><p class="mb-0 text-break">{{ $target->url }}</p></div><x-status-badge :status="$target->status" /></div>

    @if($target->error_message)<div class="alert alert-danger"><strong>Lỗi mục tiêu:</strong> {{ $target->error_message }}</div>@endif

    <div class="row g-5">
        <div class="col-xl-8"><div class="card mg-card"><div class="card-header border-0"><h2 class="mg-card-title">Dòng thời gian xử lý</h2></div><div class="card-body">
            @forelse($target->events as $event)
                <div class="border rounded p-4 mb-3">
                    <div class="d-flex justify-content-between gap-3"><div><strong>{{ \App\Support\UiText::label($event->stage) }}</strong> <span class="text-muted">· {{ $event->service }}</span></div><x-status-badge :status="$event->status" /></div>
                    <div class="mt-2">{{ $event->message }}</div>
                    <small class="text-muted">{{ $event->started_at?->format('d/m/Y H:i:s') }} · {{ $event->duration_ms !== null ? $event->duration_ms.' ms' : 'đang chạy' }}
                        @if($event->http_status) · HTTP {{ $event->http_status }} @endif
                        @if($event->request_id) · yêu cầu {{ $event->request_id }} @endif
                    </small>
                    @if($event->context)<details class="mt-3"><summary>Ngữ cảnh gỡ lỗi đã làm sạch</summary><pre class="bg-light p-3 mt-2 rounded text-wrap">{{ json_encode($event->context, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
                </div>
            @empty
                <p class="text-muted">Chưa có sự kiện. Mục tiêu có thể đang chờ tiến trình xử lý hoặc được tạo trước khi bổ sung dữ liệu đo từ xa.</p>
            @endforelse
        </div></div></div>
        <div class="col-xl-4">
            <div class="card mg-card mb-5"><div class="card-body">
                <h2 class="mg-card-title">Thông tin chạy</h2>
                <dl class="row mt-4"><dt class="col-6">Lô</dt><dd class="col-6">{{ $target->batch_number }}</dd><dt class="col-6">Số lần thử</dt><dd class="col-6">{{ $target->attempts }}</dd><dt class="col-6">Giai đoạn</dt><dd class="col-6">{{ \App\Support\UiText::label($target->current_stage ?? 'waiting') }}</dd><dt class="col-6">Tái sử dụng</dt><dd class="col-6">{{ $target->analysis_reused ? 'có' : 'không' }}</dd><dt class="col-6">Đã thử AI</dt><dd class="col-6">{{ $target->ai_attempted ? 'có' : 'không' }}</dd><dt class="col-6">Token AI</dt><dd class="col-6">{{ $target->ai_input_tokens }} / {{ $target->ai_output_tokens }}</dd></dl>
            </div></div>
            <div class="card mg-card"><div class="card-body"><h2 class="mg-card-title">Phát hiện trong lượt quét này</h2>
                @forelse($target->page?->findings ?? [] as $finding)
                    <a class="d-block border-bottom py-3" href="{{ route('findings.show', $finding) }}"><strong>{{ \App\Support\UiText::text($finding->title) }}</strong><span class="d-block text-muted">{{ $finding->rule_key }} · {{ $finding->confidence }}%</span></a>
                @empty <p class="text-muted mt-3">Không có phát hiện.</p> @endforelse
            </div></div>
        </div>
    </div>
@endsection
