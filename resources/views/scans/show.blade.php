@extends('layouts.app')

@section('title', 'Lượt quét #'.$scan->id)

@section('content')
    <div class="mg-breadcrumb"><a href="{{ route('scans.index') }}">Trung tâm quét</a><i class="bi bi-chevron-right"></i><span>#{{ $scan->id }}</span></div>
    <div class="mg-page-heading">
        <div><h1>Lượt quét #{{ $scan->id }} · {{ $scan->website->domain }}</h1>
            <p class="mb-0">Danh sách đầy đủ URL đã phát hiện và trạng thái xử lý hiện tại.</p>
        </div>
        <x-status-badge :status="$scan->status" />
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-2"><x-metric-card label="Tiến độ" :value="$scan->progress.'%'" note="Toàn lượt quét" tone="primary" icon="bi-activity" /></div>
        <div class="col-md-2"><x-metric-card label="Đã kiểm tra" :value="$scan->pages_scanned.'/'.$scan->pages_discovered" note="URL" tone="success" icon="bi-check2-circle" /></div>
        <div class="col-md-2"><x-metric-card label="Đang chạy" :value="(string)$scan->running_targets_count" note="Đang xử lý" tone="primary" icon="bi-cpu" /></div>
        <div class="col-md-2"><x-metric-card label="Đang chờ" :value="(string)$scan->queued_targets_count" note="Chờ tiến trình" tone="warning" icon="bi-hourglass" /></div>
        <div class="col-md-2"><x-metric-card label="Tái sử dụng" :value="(string)$scan->reused_targets_count" note="Không gọi API" tone="success" icon="bi-recycle" /></div>
        <div class="col-md-2"><x-metric-card label="Thất bại" :value="(string)$scan->failed_targets_count" note="Cần gỡ lỗi" tone="danger" icon="bi-x-octagon" /></div>
    </div>

    @if($scan->error_message)<div class="alert alert-danger"><strong>Lỗi quét:</strong> {{ $scan->error_message }}</div>@endif

    <div class="card mg-card"><div class="card-body">
        <div class="table-responsive"><table class="table align-middle table-row-dashed gy-4 mg-table">
            <thead><tr><th>#</th><th>URL</th><th>Trạng thái</th><th>Giai đoạn hiện tại</th><th>Số lần thử</th><th>Phát hiện</th><th>Sự kiện</th><th>Lỗi</th><th></th></tr></thead>
            <tbody id="scan-targets">
            @forelse($targets as $target)
                <tr data-target-id="{{ $target->id }}">
                    <td>{{ $target->position + 1 }}</td>
                    <td class="mw-350px"><span class="d-block text-truncate" title="{{ $target->url }}">{{ $target->url }}</span>
                        @if($target->page)<small class="text-muted">{{ number_format($target->page->word_count) }} từ · HTTP {{ $target->page->status_code }}</small>@endif
                    </td>
                    <td data-field="status"><x-status-badge :status="$target->status" /></td>
                    <td data-field="stage"><code>{{ \App\Support\UiText::label($target->current_stage ?? 'waiting') }}</code></td>
                    <td data-field="attempts">{{ $target->attempts }}</td>
                    <td data-field="findings">{{ $target->findings_count }}</td>
                    <td>{{ $target->events_count }}</td>
                    <td data-field="error" class="text-danger mw-250px"><span class="d-block text-truncate" title="{{ $target->error_message }}">{{ $target->error_message }}</span></td>
                    <td><a class="btn btn-sm btn-light-primary" href="{{ route('scans.targets.show', [$scan, $target]) }}">Gỡ lỗi</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-10">URL đang được phát hiện, chưa tạo mục tiêu quét.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $targets->links() }}
    </div></div>
@endsection

@push('page-scripts')
<script>
(() => {
    const endpoint = @json(route('scans.targets.live', $scan));
    const statusLabels = {queued:'Đang chờ', running:'Đang chạy', completed:'Hoàn tất', reused:'Đã tái sử dụng', failed:'Thất bại', cancelled:'Đã hủy'};
    const stageLabels = {crawl:'Thu thập dữ liệu', reuse:'Tái sử dụng kết quả', local_rules:'Quy tắc cục bộ', sightengine:'Sightengine', gemini:'Gemini', anthropic:'Claude/Anthropic', ollama:'Ollama', openai_compatible:'OpenAI', pipeline:'Quy trình xử lý'};
    const refresh = () => fetch(endpoint, {headers:{Accept:'application/json'}}).then(r => r.json()).then(data => {
        data.targets.forEach(target => {
            const row = document.querySelector(`[data-target-id="${target.id}"]`);
            if (!row) return;
            row.querySelector('[data-field="status"]').textContent = statusLabels[target.status] || target.status;
            row.querySelector('[data-field="stage"]').textContent = stageLabels[target.stage] || target.stage || 'Đang chờ';
            row.querySelector('[data-field="attempts"]').textContent = target.attempts;
            row.querySelector('[data-field="findings"]').textContent = target.findings;
            row.querySelector('[data-field="error"]').textContent = target.error || '';
        });
    }).catch(() => {});
    setInterval(refresh, 3000);
})();
</script>
@endpush
