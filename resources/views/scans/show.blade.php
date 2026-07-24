@extends('layouts.app')

@section('title', 'Scan #'.$scan->id)

@section('content')
    <div class="mg-breadcrumb"><a href="{{ route('scans.index') }}">Scan center</a><i class="bi bi-chevron-right"></i><span>#{{ $scan->id }}</span></div>
    <div class="mg-page-heading">
        <div><h1>Scan #{{ $scan->id }} · {{ $scan->website->domain }}</h1>
            <p class="mb-0">Danh sách đầy đủ URL đã discover và trạng thái xử lý hiện tại.</p>
        </div>
        <x-status-badge :status="$scan->status" />
    </div>

    <div class="row g-4 mb-5">
        <div class="col-md-2"><x-metric-card label="Progress" :value="$scan->progress.'%'" note="Toàn scan" tone="primary" icon="bi-activity" /></div>
        <div class="col-md-2"><x-metric-card label="Checked" :value="$scan->pages_scanned.'/'.$scan->pages_discovered" note="URL" tone="success" icon="bi-check2-circle" /></div>
        <div class="col-md-2"><x-metric-card label="Running" :value="(string)$scan->running_targets_count" note="Đang xử lý" tone="primary" icon="bi-cpu" /></div>
        <div class="col-md-2"><x-metric-card label="Queued" :value="(string)$scan->queued_targets_count" note="Chờ worker" tone="warning" icon="bi-hourglass" /></div>
        <div class="col-md-2"><x-metric-card label="Reused" :value="(string)$scan->reused_targets_count" note="Không gọi API" tone="success" icon="bi-recycle" /></div>
        <div class="col-md-2"><x-metric-card label="Failed" :value="(string)$scan->failed_targets_count" note="Cần debug" tone="danger" icon="bi-x-octagon" /></div>
    </div>

    @if($scan->error_message)<div class="alert alert-danger"><strong>Scan error:</strong> {{ $scan->error_message }}</div>@endif

    <div class="card mg-card"><div class="card-body">
        <div class="table-responsive"><table class="table align-middle table-row-dashed gy-4 mg-table">
            <thead><tr><th>#</th><th>URL</th><th>Status</th><th>Current stage</th><th>Attempts</th><th>Findings</th><th>Events</th><th>Error</th><th></th></tr></thead>
            <tbody id="scan-targets">
            @forelse($targets as $target)
                <tr data-target-id="{{ $target->id }}">
                    <td>{{ $target->position + 1 }}</td>
                    <td class="mw-350px"><span class="d-block text-truncate" title="{{ $target->url }}">{{ $target->url }}</span>
                        @if($target->page)<small class="text-muted">{{ number_format($target->page->word_count) }} words · HTTP {{ $target->page->status_code }}</small>@endif
                    </td>
                    <td data-field="status"><x-status-badge :status="$target->status" /></td>
                    <td data-field="stage"><code>{{ $target->current_stage ?? 'waiting' }}</code></td>
                    <td data-field="attempts">{{ $target->attempts }}</td>
                    <td data-field="findings">{{ $target->findings_count }}</td>
                    <td>{{ $target->events_count }}</td>
                    <td data-field="error" class="text-danger mw-250px"><span class="d-block text-truncate" title="{{ $target->error_message }}">{{ $target->error_message }}</span></td>
                    <td><a class="btn btn-sm btn-light-primary" href="{{ route('scans.targets.show', [$scan, $target]) }}">Debug</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-10">URL đang được discovery, chưa tạo target.</td></tr>
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
    const refresh = () => fetch(endpoint, {headers:{Accept:'application/json'}}).then(r => r.json()).then(data => {
        data.targets.forEach(target => {
            const row = document.querySelector(`[data-target-id="${target.id}"]`);
            if (!row) return;
            row.querySelector('[data-field="status"]').textContent = target.status;
            row.querySelector('[data-field="stage"]').textContent = target.stage || 'waiting';
            row.querySelector('[data-field="attempts"]').textContent = target.attempts;
            row.querySelector('[data-field="findings"]').textContent = target.findings;
            row.querySelector('[data-field="error"]').textContent = target.error || '';
        });
    }).catch(() => {});
    setInterval(refresh, 3000);
})();
</script>
@endpush
