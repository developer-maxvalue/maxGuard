@extends('layouts.app')

@section('title', 'Scan center')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Scan center</h1>
            <p>Run full-site or focused compliance checks without blocking the web request.</p>
        </div>
    </div>

    @error('queue')
        <div class="alert alert-danger d-flex align-items-start gap-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-3"></i>
            <div><strong class="d-block mb-1">Scan was not queued</strong>{{ $message }}</div>
        </div>
    @enderror

    <div class="row g-5">
        <div class="col-xl-7">
            <div class="card mg-card">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Start a compliance scan</h2>
                        <p class="mg-card-subtitle">Each request is dispatched to the configured scan queue.</p>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <form method="POST" action="{{ route('scans.store') }}">
                        @csrf
                        <div class="mb-6"><label class="form-label fw-semibold">Website</label><select name="site"
                                class="form-select form-select-solid @error('site') is-invalid @enderror">
                                <option value="">Choose a website</option>
                                @foreach ($sites as $site)
                                    <option value="{{ $site['domain'] }}" @selected(old('site') === $site['domain'])>{{ $site['domain'] }}
                                    </option>
                                @endforeach
                            </select>
                            @error('site')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-7"><label class="form-label fw-semibold">Scan type</label>
                            <div class="mg-scan-options">
                                @foreach ([['full', 'Full compliance', 'Copyright, content quality, ads, trust and privacy', 'bi-shield-check'], ['copyright', 'Copyright & duplicate', 'Text similarity, image and media provenance', 'bi-c-circle'], ['ads', 'Ad experience', 'Density, placement and accidental-click risk', 'bi-badge-ad'], ['privacy', 'Privacy & consent', 'CMP, consent mode and disclosure checks', 'bi-fingerprint']] as $type)
                                    <label><input type="radio" name="scan_type" value="{{ $type[0] }}"
                                            @checked(old('scan_type', 'full') === $type[0])><span><i
                                                class="bi {{ $type[3] }}"></i><strong>{{ $type[1] }}</strong><small>{{ $type[2] }}</small></span></label>
                                @endforeach
                            </div>
                        </div>
                        <div class="row g-5 mb-7">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="max-urls">Maximum newest posts</label>
                                <input id="max-urls" type="number" name="max_urls" min="1"
                                    max="{{ $maxUrlSafetyLimit }}" value="{{ old('max_urls') }}" placeholder="All posts"
                                    class="form-control form-control-solid @error('max_urls') is-invalid @enderror">
                                <div class="form-text">When set, MaxGuard reads every sitemap and selects the newest posts
                                    by
                                    <code>&lt;lastmod&gt;</code>. Leave empty to scan all discovered URLs.
                                </div>
                                @error('max_urls')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold d-block">AI policy analysis</label>
                                <label class="form-check form-switch form-check-custom form-check-solid mt-3">
                                    <input class="form-check-input" type="checkbox" name="use_ai" value="1"
                                        @checked((bool) old('use_ai', $aiInfo['ready'])) @disabled(!$aiInfo['ready'])>
                                    <span class="form-check-label fw-semibold">Analyze page meaning with
                                        {{ $aiInfo['model'] }}</span>
                                </label>
                                @if ($aiInfo['ready'])
                                    <div class="form-text">AI safety cap:
                                        {{ $aiInfo['page_limit'] === 0 ? 'all crawled pages' : number_format($aiInfo['page_limit']) . ' pages per scan' }}.
                                    </div>
                                @else
                                    <div class="form-text text-warning">Set <code>OPENAI_API_KEY</code> and
                                        <code>MAXGUARD_AI_ENABLED=true</code> to enable AI.
                                    </div>
                                @endif
                                @error('use_ai')
                                    <div class="text-danger fs-8 mt-2">{{ $message }}</div>
                                @enderror
                                <label class="form-check form-check-custom form-check-solid mt-4">
                                    <input class="form-check-input" type="checkbox" name="force_rescan" value="1"
                                        @checked((bool) old('force_rescan', false))>
                                    <span class="form-check-label">Force re-analyze unchanged URLs</span>
                                </label>
                                <div class="form-text">Leave off to reuse compatible results for unchanged content.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end"><button class="btn btn-primary px-6"><i
                                    class="bi bi-play-fill me-2"></i>Queue scan</button></div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card mg-card mb-5">
                <div class="card-body p-6">
                    <div class="d-flex align-items-center justify-content-between mb-5">
                        <div>
                            <div class="mg-eyebrow mb-2">Queue activity</div>
                            <h2 class="mg-card-title mb-0">{{ $scanStats['running'] }} scan(s) running</h2>
                        </div><span class="mg-icon-box mg-icon-box-success"><i class="bi bi-cpu"></i></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted fs-7 mb-2"><span>Estimated
                            utilization</span><strong class="text-gray-800">{{ $scanStats['utilization'] }}%</strong></div>
                    <div class="progress h-8px">
                        <div class="progress-bar bg-primary" style="width:{{ $scanStats['utilization'] }}%"></div>
                    </div>
                    <div class="row g-3 mt-4">
                        <div class="col-6">
                            <div class="mg-mini-stat"><strong>{{ $scanStats['target_running'] }}</strong><span>URLs in active batches</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="mg-mini-stat"><strong>{{ $scanStats['target_queued'] }}</strong><span>URLs waiting</span></div>
                        </div>
                    </div>
                    <div class="mt-5 p-4 rounded bg-light-primary">
                        @if ($queueInfo['driver'] === 'sync')
                            <div class="mg-eyebrow mb-2">Synchronous queue</div>
                            <span class="d-block fs-8 text-muted">No worker is required, but long scans can time out.
                                Database or Redis queue is recommended.</span>
                        @else
                            <div class="mg-eyebrow mb-2">1 control worker</div>
                            <code class="d-block text-break">{{ $queueInfo['control_worker_command'] }}</code>
                            <div class="mg-eyebrow mb-2 mt-4">{{ $queueInfo['page_workers'] }} parallel page workers</div>
                            <code class="d-block text-break">{{ $queueInfo['page_worker_command'] }}</code>
                            <span class="d-block fs-9 text-muted mt-2">Run the page command in
                                {{ $queueInfo['page_workers'] }} processes, preferably with Supervisor.</span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="card mg-card">
                <div class="card-header border-0 pt-2">
                    <div class="card-title">
                        <h2 class="mg-card-title">Safe scan defaults</h2>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <ul class="mg-check-list">
                        <li><i class="bi bi-check2"></i>Rate-limit per host</li>
                        <li><i class="bi bi-check2"></i>Respect robots.txt rules</li>
                        <li><i class="bi bi-check2"></i>Store immutable evidence</li>
                        <li><i class="bi bi-check2"></i>Retry transient failures</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mg-card mt-5">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Recent scans</h2>
                <p class="mg-card-subtitle">Queue and worker status from the database.</p>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr>
                            <th>Website</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>URLs checked / total</th>
                            <th>Limit & AI</th>
                            <th>Findings</th>
                            <th>Started</th>
                        </tr>
                    </thead>
                    <tbody id="mg-live-scans-body">
                        @forelse($recentScans as $scan)
                            <tr>
                                <td class="fw-semibold">{{ $scan->website->domain }}</td>
                                <td>{{ ucfirst($scan->type) }}</td>
                                <td><x-status-badge :status="$scan->status" />
                                    @if (data_get($scan->meta, 'is_sampled', false))
                                        <span class="badge badge-light-info mt-1">Latest sample</span>
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
                                    <span class="d-block text-muted fs-9">checked / selected</span>
                                    <span
                                        class="d-block text-muted fs-9 mt-1">{{ number_format(max(0, $scan->pages_scanned - $scan->pages_skipped_unchanged)) }}
                                        analyzed</span>
                                    @if ($scan->pages_skipped_unchanged > 0)
                                        <span
                                            class="d-block text-success fs-9 mt-1">{{ number_format($scan->pages_skipped_unchanged) }}
                                            unchanged · analysis skipped</span>
                                    @endif
                                    @if (data_get($scan->meta, 'is_sampled', false))
                                        <span class="d-block text-info fs-9 mt-1">Latest
                                            {{ number_format($scan->pages_discovered) }} of
                                            {{ number_format(data_get($scan->meta, 'available_urls', $scan->pages_discovered)) }}
                                            posts selected by lastmod</span>
                                    @endif
                                    @if ($scan->meta)
                                        <span
                                            class="d-block text-muted fs-9 mt-1">{{ data_get($scan->meta, 'sitemap_files_processed', 0) }}
                                            sitemaps ·
                                            {{ data_get($scan->meta, 'failed_requests', 0) }} failed ·
                                            {{ data_get($scan->meta, 'blocked_by_robots', 0) }} blocked</span>
                                    @endif
                                    @if (data_get($scan->meta, 'parallel_scan', false))
                                        <span class="d-block text-primary fs-9 mt-1">
                                            {{ number_format(data_get($scan->meta, 'batches_completed', 0)) }} /
                                            {{ number_format(data_get($scan->meta, 'batches_total', 0)) }} batches ·
                                            {{ number_format($scan->targets_running_count ?? data_get($scan->meta, 'targets_running', 0)) }} URLs active ·
                                            {{ number_format($scan->targets_queued_count ?? data_get($scan->meta, 'targets_queued', 0)) }} waiting
                                        </span>
                                        @if (($scan->targets_failed_count ?? data_get($scan->meta, 'targets_failed', 0)) > 0)
                                            <span class="d-block text-danger fs-9 mt-1">
                                                {{ number_format($scan->targets_failed_count ?? data_get($scan->meta, 'targets_failed', 0)) }} URL jobs failed
                                            </span>
                                        @endif
                                    @endif
                                    @if ($scan->current_url)
                                        <span class="d-block text-primary fs-9 mt-1 text-truncate mw-250px"
                                            title="{{ $scan->current_url }}">Now: {{ $scan->current_url }}</span>
                                    @endif
                                </td>
                                <td>
                                    <strong>{{ $scan->max_urls ? number_format($scan->max_urls) : 'Global' }}</strong><span
                                        class="d-block text-muted fs-9">{{ data_get($scan->meta, 'is_sampled', false) ? 'newest-post sample' : 'URL cap' }}</span>
                                    @if ($scan->use_ai)
                                        <span class="badge badge-light-info mt-2"><i class="bi bi-stars me-1"></i>AI
                                            {{ number_format($scan->ai_pages_analyzed) }} pages</span>
                                        @if (data_get($scan->meta, 'ai_limit_reached', false))
                                            <span class="d-block text-warning fs-9 mt-1">AI safety cap reached</span>
                                        @endif
                                        @if (data_get($scan->meta, 'ai_errors', 0) > 0)
                                            <span
                                                class="d-block text-danger fs-9 mt-1">{{ data_get($scan->meta, 'ai_errors') }}
                                                AI errors</span>
                                        @endif
                                    @else
                                        <span class="d-block text-muted fs-9 mt-2">Rules only</span>
                                    @endif
                                    @if ($scan->force_rescan)
                                        <span class="d-block text-warning fs-9 mt-1">Forced re-analysis</span>
                                    @endif
                                </td>
                                <td><strong>{{ number_format($scan->findings_count) }}</strong><span
                                        class="d-block text-muted fs-9">detected this scan</span>
                                    @if ($scan->ai_findings_count > 0)
                                        <span
                                            class="d-block text-info fs-9 mt-1">{{ number_format($scan->ai_findings_count) }}
                                            from AI</span>
                                    @endif
                                </td>
                                <td class="text-muted">{{ ($scan->started_at ?? $scan->created_at)->diffForHumans() }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-10">No scans have been queued yet.
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
                <h2 class="mg-card-title">Live findings report</h2>
                <p class="mg-card-subtitle">New issues appear as soon as each URL is analyzed.</p>
            </div>
            <div class="card-toolbar d-flex gap-2">
                <span class="badge badge-light-success"><span class="mg-pulse-dot me-1"></span>Live</span>
                <a href="{{ route('findings.export.xlsx') }}" class="btn btn-sm btn-light-success"><i
                        class="bi bi-file-earmark-excel me-2"></i>Export Excel</a>
                <a href="{{ route('findings.index') }}" class="btn btn-sm btn-light-primary">All findings</a>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr>
                            <th>Site / URL</th>
                            <th>Issue</th>
                            <th>Analyzer</th>
                            <th>Severity</th>
                            <th>Confidence</th>
                            <th>Detected</th>
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
                                <td><strong>{{ $finding->title }}</strong><span
                                        class="d-block text-muted fs-9">{{ $finding->category }}</span></td>
                                <td><span
                                        class="badge {{ str_starts_with($finding->rule_key, 'ai.') ? 'badge-light-info' : 'badge-light' }}">{{ str_starts_with($finding->rule_key, 'ai.') ? 'AI' : 'Rules' }}</span>
                                </td>
                                <td><x-status-badge :status="$finding->severity" /></td>
                                <td><strong>{{ $finding->confidence }}%</strong></td>
                                <td class="text-muted">{{ $finding->last_seen_at->diffForHumans() }}</td>
                                <td class="text-end"><a href="{{ route('findings.show', $finding) }}"
                                        class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-10">No findings have been detected
                                    yet. This table updates while scans run.</td>
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
