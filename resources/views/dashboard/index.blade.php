@extends('layouts.app')

@section('title', 'Portfolio overview')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Portfolio overview</h1>
            <p>See risk, revenue exposure and remediation priorities across every site.</p>
        </div>
        <form method="POST" action="{{ route('scans.store') }}">
            @csrf
            <input type="hidden" name="site" value="all-sites">
            <input type="hidden" name="scan_type" value="full">
            <button class="btn btn-primary px-5" type="submit">
                <i class="bi bi-upc-scan me-2"></i>Run full scan
            </button>
        </form>
    </div>

    <div class="row g-5 mb-5">
        @foreach ($metrics as $metric)
            <div class="col-sm-6 col-xxl-3">
                <x-metric-card :label="$metric['label']" :value="$metric['value']" :note="$metric['note']" :tone="$metric['tone']" :icon="$metric['icon']" />
            </div>
        @endforeach
    </div>

    <div class="row g-5 mb-5">
        <div class="col-xl-8">
            <div class="card mg-card h-100">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Compliance trend</h2>
                        <p class="mg-card-subtitle">Portfolio score over the last 12 weeks</p>
                    </div>
                    <div class="card-toolbar">
                        <span class="badge badge-light">Last 12 weeks</span>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div id="compliance-trend-chart" class="mg-chart" aria-label="Compliance score trend chart"></div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mg-card h-100">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Portfolio health</h2>
                        <p class="mg-card-subtitle">{{ $health['total'] }} sites ranked by current risk</p>
                    </div>
                </div>
                <div class="card-body pt-2">
                    <div class="mg-health-grid">
                        <div class="mg-health-donut" style="--healthy: {{ $health['healthy_percent'] }}; --review: {{ $health['review_percent'] }}" role="img"
                            aria-label="{{ $health['healthy'] }} healthy, {{ $health['review'] }} need review, {{ $health['critical'] }} critical">
                            <div><strong>{{ $health['total'] }}</strong><small>sites</small></div>
                        </div>
                        <div class="mg-health-legend">
                            <div><span class="bg-success"></span><em>Healthy</em><strong>{{ $health['healthy'] }}</strong></div>
                            <div><span class="bg-warning"></span><em>Needs review</em><strong>{{ $health['review'] }}</strong></div>
                            <div><span class="bg-danger"></span><em>Critical</em><strong>{{ $health['critical'] }}</strong></div>
                        </div>
                    </div>
                    <a href="{{ route('sites.index') }}" class="btn btn-light-primary w-100 mt-5">View all sites</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mg-card">
        <div class="card-header border-0 pt-2">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Sites requiring attention</h2>
                <p class="mg-card-subtitle">Sorted by potential AdSense impact</p>
            </div>
            <div class="card-toolbar">
                <a href="{{ route('sites.index') }}" class="btn btn-sm btn-light">Review portfolio</a>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>Site</th>
                            <th>Score</th>
                            <th>Top risk</th>
                            <th>Findings</th>
                            <th>Last scan</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td>
                                    <a href="{{ route('sites.show', $site['slug']) }}" class="mg-site-cell">
                                        <span>{{ strtoupper(substr($site['domain'], 0, 1)) }}</span>
                                        <strong>{{ $site['domain'] }}</strong>
                                    </a>
                                </td>
                                <td><strong class="mg-score-text mg-score-{{ $site['status'] }}">{{ $site['score'] }}</strong></td>
                                <td class="text-gray-700">{{ $site['top_risk'] }}</td>
                                <td><strong>{{ $site['findings'] }}</strong></td>
                                <td class="text-muted">{{ $site['last_scan'] }}</td>
                                <td class="text-end"><x-status-badge :status="$site['status']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('page-scripts')
    <script>
        window.MaxGuardPage = {
            trend: @json($trend),
            trendLabels: ['May 6', '', 'May 20', '', 'Jun 3', '', 'Jun 17', '', 'Jul 1', '', 'Jul 15', '', 'Jul 29', '', 'Aug 12', 'Now']
        };
    </script>
@endpush
