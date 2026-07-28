@extends('layouts.app')

@section('title', 'Administration')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>System administration</h1>
            <p>Monitor users and manage all websites, scans and findings across MaxGuard.</p>
        </div>
    </div>

    <div class="row g-4 mb-5">
        <div class="col-xl-2 col-md-4"><x-metric-card label="Users" :value="number_format($metrics['users'])" note="registered accounts" tone="primary" icon="bi-people" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Sites" :value="number_format($metrics['sites'])" note="all owners" tone="info" icon="bi-globe2" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Active scans" :value="number_format($metrics['running_scans'])" note="queued or running" tone="primary" icon="bi-upc-scan" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Open findings" :value="number_format($metrics['open_findings'])" note="system-wide" tone="warning" icon="bi-exclamation-diamond" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Critical" :value="number_format($metrics['critical_findings'])" note="requires attention" tone="danger" icon="bi-exclamation-triangle" /></div>
        <div class="col-xl-2 col-md-4"><x-metric-card label="Est. revenue" :value="'$'.number_format((float) $metrics['monthly_revenue'])" note="monthly input total" tone="success" icon="bi-currency-dollar" /></div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Users</h2>
                <p class="mg-card-subtitle">20 most recently registered accounts.</p>
            </div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead><tr><th>User</th><th>Role</th><th>Sites</th><th>Registered</th></tr></thead>
                    <tbody>
                        @forelse($users as $user)
                            <tr>
                                <td><strong>{{ $user->name }}</strong><span class="d-block text-muted fs-9">{{ $user->email }}</span></td>
                                <td><span class="badge {{ $user->is_admin ? 'badge-light-danger' : 'badge-light' }}">{{ $user->is_admin ? 'Administrator' : 'User' }}</span></td>
                                <td>{{ number_format($user->websites_count) }}</td>
                                <td class="text-muted">{{ $user->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-8">No users found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mg-card mb-5">
        <div class="card-header border-0">
            <div class="card-title d-block">
                <h2 class="mg-card-title">Websites requiring attention</h2>
                <p class="mg-card-subtitle">20 lowest-scoring websites across all users.</p>
            </div>
            <div class="card-toolbar"><a href="{{ route('sites.index') }}" class="btn btn-sm btn-light-primary">Manage all sites</a></div>
        </div>
        <div class="card-body pt-0">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-4 mg-table">
                    <thead><tr><th>Website</th><th>Owner</th><th>Score</th><th>Status</th><th>Findings</th><th>Last scan</th><th></th></tr></thead>
                    <tbody>
                        @forelse($sites as $site)
                            <tr>
                                <td><strong>{{ $site->domain }}</strong><span class="d-block text-muted fs-9">{{ $site->name }}</span></td>
                                <td>{{ $site->owner?->email ?? 'Unassigned' }}</td>
                                <td><strong class="mg-score-text mg-score-{{ $site->status }}">{{ $site->overall_score }}</strong></td>
                                <td><x-status-badge :status="$site->status" /></td>
                                <td>{{ number_format($site->open_findings_count) }}</td>
                                <td class="text-muted">{{ $site->last_scanned_at?->diffForHumans() ?? 'Never' }}</td>
                                <td class="text-end"><a href="{{ route('sites.show', $site) }}" class="btn btn-sm btn-light-primary">Open</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-8">No websites found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-5">
        <div class="col-xl-6">
            <div class="card mg-card h-100">
                <div class="card-header border-0">
                    <div class="card-title"><h2 class="mg-card-title">Recent scans</h2></div>
                    <div class="card-toolbar"><a href="{{ route('scans.index') }}" class="btn btn-sm btn-light-primary">All scans</a></div>
                </div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed gy-4 mg-table">
                            <thead><tr><th>Site / owner</th><th>Status</th><th>Progress</th><th></th></tr></thead>
                            <tbody>
                                @forelse($scans as $scan)
                                    <tr>
                                        <td><strong>{{ $scan->website->domain }}</strong><span class="d-block text-muted fs-9">{{ $scan->website->owner?->email ?? 'Unassigned' }}</span></td>
                                        <td><x-status-badge :status="$scan->status" /></td>
                                        <td>{{ $scan->progress }}%</td>
                                        <td class="text-end"><a href="{{ route('scans.show', $scan) }}" class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-8">No scans found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card mg-card h-100">
                <div class="card-header border-0">
                    <div class="card-title"><h2 class="mg-card-title">Priority findings</h2></div>
                    <div class="card-toolbar"><a href="{{ route('findings.index') }}" class="btn btn-sm btn-light-primary">All findings</a></div>
                </div>
                <div class="card-body pt-0">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed gy-4 mg-table">
                            <thead><tr><th>Issue / owner</th><th>Severity</th><th>Confidence</th><th></th></tr></thead>
                            <tbody>
                                @forelse($findings as $finding)
                                    <tr>
                                        <td><strong>{{ $finding->title }}</strong><span class="d-block text-muted fs-9">{{ $finding->website->domain }} · {{ $finding->website->owner?->email ?? 'Unassigned' }}</span></td>
                                        <td><x-status-badge :status="$finding->severity" /></td>
                                        <td>{{ $finding->confidence }}%</td>
                                        <td class="text-end"><a href="{{ route('findings.show', $finding) }}" class="btn btn-sm btn-icon btn-light"><i class="bi bi-arrow-right"></i></a></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-8">No open findings.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
