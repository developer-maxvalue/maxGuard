@extends('layouts.app')

@section('title', $site['domain'])

@section('content')
    <div class="mg-breadcrumb"><a href="{{ route('sites.index') }}">Sites</a><i
            class="bi bi-chevron-right"></i><span>{{ $site['domain'] }}</span></div>
    <div class="mg-page-heading align-items-end">
        <div>
            <div class="d-flex align-items-center flex-wrap gap-3">
                <h1 class="mb-0">{{ $site['domain'] }}</h1>
                <x-status-badge :status="$site['status']" />
            </div>
            <p class="mt-2 mb-0">Last scanned {{ $site['last_scan'] }} · {{ number_format($site['pages']) }} pages analyzed
            </p>
        </div>
        <div class="d-flex gap-3">
            <a href="{{ route('findings.index', ['q' => $site['domain']]) }}" class="btn btn-light"><i
                    class="bi bi-folder2-open me-2"></i>Open cases</a>
            <form method="POST" action="{{ route('scans.store') }}">
                @csrf
                <input type="hidden" name="site" value="{{ $site['domain'] }}">
                <input type="hidden" name="scan_type" value="full">
                @if ($aiReady)
                    <input type="hidden" name="use_ai" value="1">
                @endif
                <div class="input-group">
                    <input class="form-control form-control-solid" style="max-width: 150px" type="number" name="max_urls"
                        min="1" max="{{ $maxUrlSafetyLimit }}" placeholder="Latest posts">
                    <button class="btn btn-primary"><i
                            class="bi bi-arrow-repeat me-2"></i>Rescan{{ $aiReady ? ' + AI' : '' }}</button>
                </div>
            </form>
        </div>
    </div>

    @if ($site['coverage_partial'])
        <div class="alert alert-warning d-flex align-items-start gap-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-3"></i>
            <div><strong class="d-block mb-1">The last scan had partial coverage</strong>Scanned
                {{ number_format($site['pages']) }} of
                {{ number_format($site['discovered_pages']) }} discovered URLs. Check the latest scan metadata, sitemap
                errors, robots.txt and configured limits.
            </div>
        </div>
    @endif

    <div class="row g-5 mb-5">
        <div class="col-xl-3 col-md-6">
            <div class="card mg-card h-100">
                <div class="card-body p-6">
                    <div class="mg-eyebrow mb-4">Overall score</div>
                    <div class="d-flex align-items-center gap-5"><x-score-ring :score="$site['score']" />
                        <div><strong class="mg-score-{{ $site['status'] }} d-block mb-2">{{ ucfirst($site['status']) }}
                                exposure</strong><span class="text-muted fs-7">Prioritize the highest-confidence findings
                                first.</span></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6"><x-metric-card label="Revenue at risk" :value="$site['revenue_risk']"
                note="Estimated AdSense impact" tone="danger" icon="bi-currency-dollar" /></div>
        <div class="col-xl-3 col-md-6"><x-metric-card label="Pages analyzed" :value="number_format($site['pages'])" :note="$site['coverage'] . '% of ' . number_format($site['discovered_pages']) . ' discovered URLs'"
                tone="primary" icon="bi-file-earmark-text" /></div>
        <div class="col-xl-3 col-md-6"><x-metric-card label="Open findings" :value="(string) $site['findings']"
                note="Critical issues need review" tone="warning" icon="bi-exclamation-diamond" /></div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Policy health breakdown</h2>
                <p class="mg-card-subtitle">Scores combine page evidence, severity and potential monetization impact.</p>
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
        <div class="card-header border-0 pt-2"><div class="card-title d-block">
            <h2 class="mg-card-title">GA4 traffic · last 7 days</h2>
            <p class="mg-card-subtitle">Priority scans follow this order from highest to lowest traffic.</p>
        </div></div>
        <div class="card-body pt-0">
            @if (!$ga4)
                <a class="btn btn-light-primary" href="{{ route('ga4.connect', $site['slug']) }}"><i class="bi bi-google me-2"></i>Connect Google Analytics</a>
            @else
                <form class="row g-3 align-items-end mb-5" method="POST" action="{{ route('ga4.update', $site['slug']) }}">
                    @csrf @method('PATCH')
                    <div class="col-md-5"><label class="form-label">GA4 property ID</label>
                        <input class="form-control" name="property_id" value="{{ $ga4->property_id }}" placeholder="123456789" required>
                    </div>
                    <div class="col-auto"><button class="btn btn-light-primary">Save property</button></div>
                </form>
                @if ($ga4->property_id)
                    <form method="POST" action="{{ route('ga4.sync', $site['slug']) }}" class="mb-5">@csrf
                        <button class="btn btn-primary">Sync traffic now</button>
                        <span class="text-muted ms-3">Last sync: {{ $ga4->last_synced_at?->diffForHumans() ?? 'never' }}</span>
                    </form>
                @endif
                @foreach($trafficPages as $page)
                    <div class="d-flex justify-content-between border-bottom py-2 gap-4">
                        <span class="text-truncate" title="{{ $page->url }}">{{ $page->url }}</span>
                        <strong>{{ number_format($page->ga4_views_7d) }} views</strong>
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    <div class="card mg-card">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Highest-risk URLs</h2>
                <p class="mg-card-subtitle">Start with pages most likely to cause account-level enforcement.</p>
            </div>
            <div class="card-toolbar"><a href="{{ route('findings.index') }}" class="btn btn-light-primary btn-sm">View all
                    findings</a></div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>URL</th>
                            <th>Primary issue</th>
                            <th>Severity</th>
                            <th>Evidence</th>
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
                                <td><strong>{{ $url['evidence'] }} items</strong></td>
                                <td class="text-end"><a href="{{ route('findings.show', $url['finding_id']) }}"
                                        class="btn btn-sm btn-light-primary">View
                                        evidence</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
