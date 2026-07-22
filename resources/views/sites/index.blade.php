@extends('layouts.app')

@section('title', 'Sites')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Sites</h1>
            <p>Monitor AdSense compliance health and coverage across your portfolio.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addWebsiteModal"><i class="bi bi-plus-lg me-2"></i>Add website</button>
    </div>

    <div class="card mg-card">
        <form class="card-header border-0 pt-3" method="GET">
            <div class="card-title">
                <div class="position-relative">
                    <i class="bi bi-search position-absolute top-50 translate-middle-y ms-4 text-muted"></i>
                    <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-solid ps-10 w-300px"
                        placeholder="Search domain" aria-label="Search websites">
                </div>
            </div>
            <div class="card-toolbar d-flex gap-3">
                <select name="status" class="form-select form-select-solid w-150px" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <option value="critical" @selected(request('status') === 'critical')>Critical</option>
                    <option value="high" @selected(request('status') === 'high')>High</option>
                    <option value="review" @selected(request('status') === 'review')>Review</option>
                    <option value="healthy" @selected(request('status') === 'healthy')>Healthy</option>
                    <option value="pending" @selected(request('status') === 'pending')>Pending</option>
                </select>
                <button class="btn btn-light" name="export" value="csv"><i class="bi bi-download me-2"></i>Export</button>
            </div>
        </form>
        <div class="card-body pt-2">
            <div class="table-responsive">
                <table class="table align-middle table-row-dashed gy-5 mg-table">
                    <thead>
                        <tr class="text-uppercase text-muted fs-8">
                            <th>Website</th>
                            <th>Health</th>
                            <th>Pages</th>
                            <th>Coverage</th>
                            <th>Revenue risk</th>
                            <th>Last scan</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sites as $site)
                            <tr>
                                <td>
                                    <a href="{{ route('sites.show', $site['slug']) }}" class="mg-site-cell">
                                        <span>{{ strtoupper(substr($site['domain'], 0, 1)) }}</span>
                                        <div><strong>{{ $site['domain'] }}</strong><small>{{ $site['findings'] }} open findings</small></div>
                                    </a>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-3"><strong
                                            class="mg-score-text mg-score-{{ $site['status'] }}">{{ $site['score'] }}</strong><x-status-badge :status="$site['status']" />
                                    </div>
                                </td>
                                <td>{{ number_format($site['pages']) }}</td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="progress w-80px h-6px">
                                            <div class="progress-bar bg-primary" style="width: {{ $site['coverage'] }}%"></div>
                                        </div><span>{{ $site['coverage'] }}%</span>
                                    </div>
                                </td>
                                <td class="fw-semibold {{ $site['revenue_risk'] === '$0' ? 'text-success' : 'text-danger' }}">{{ $site['revenue_risk'] }}</td>
                                <td class="text-muted">{{ $site['last_scan'] }}</td>
                                <td class="text-end"><a href="{{ route('sites.show', $site['slug']) }}" class="btn btn-sm btn-icon btn-light"><i
                                            class="bi bi-arrow-right"></i></a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if (method_exists($sites, 'links'))
                <div class="mt-5">{{ $sites->links() }}</div>
            @endif
        </div>
    </div>

    <div class="modal fade" id="addWebsiteModal" tabindex="-1" aria-labelledby="addWebsiteTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="POST" action="{{ route('sites.store') }}">
                @csrf
                <div class="modal-header border-0">
                    <div>
                        <h2 class="modal-title" id="addWebsiteTitle">Add website</h2>
                        <p class="text-muted fs-7 mb-0 mt-2">Only add domains you own or are authorized to audit.</p>
                    </div><button type="button" class="btn btn-sm btn-icon btn-light" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
                </div>
                <div class="modal-body pt-2">
                    <div class="mb-5"><label class="form-label fw-semibold">Display name</label><input type="text" name="name" value="{{ old('name') }}"
                            class="form-control form-control-solid @error('name') is-invalid @enderror" placeholder="Publisher website">
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-5"><label class="form-label fw-semibold">Start URL</label><input type="url" name="start_url"
                            value="{{ old('start_url') }}" class="form-control form-control-solid @error('start_url') is-invalid @enderror"
                            placeholder="https://example.com/">
                        @error('start_url')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">
                            Private IPs, localhost and non-standard ports are blocked.</div>
                    </div>
                    <div><label class="form-label fw-semibold">Estimated monthly AdSense revenue</label>
                        <div class="input-group input-group-solid"><span class="input-group-text">$</span><input type="number" min="0" step="0.01"
                                name="expected_monthly_revenue" value="{{ old('expected_monthly_revenue', 0) }}"
                                class="form-control @error('expected_monthly_revenue') is-invalid @enderror"></div>
                        @error('expected_monthly_revenue')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer border-0"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button
                        class="btn btn-primary">Add website</button></div>
            </form>
        </div>
    </div>
@endsection

@if ($errors->any())
    @push('page-scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                new bootstrap.Modal(document.getElementById('addWebsiteModal')).show();
            });
        </script>
    @endpush
@endif
