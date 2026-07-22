@extends('layouts.app')

@section('title', 'Scan center')

@section('content')
    <div class="mg-page-heading">
        <div>
            <h1>Scan center</h1>
            <p>Run full-site or focused compliance checks without blocking the web request.</p>
        </div>
    </div>

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
                                    <option value="{{ $site['domain'] }}">{{ $site['domain'] }}</option>
                                @endforeach
                            </select>
                            @error('site')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-7"><label class="form-label fw-semibold">Scan type</label>
                            <div class="mg-scan-options">
                                @foreach ([['full', 'Full compliance', 'Copyright, content quality, ads, trust and privacy', 'bi-shield-check'], ['copyright', 'Copyright & duplicate', 'Text similarity, image and media provenance', 'bi-c-circle'], ['ads', 'Ad experience', 'Density, placement and accidental-click risk', 'bi-badge-ad'], ['privacy', 'Privacy & consent', 'CMP, consent mode and disclosure checks', 'bi-fingerprint']] as $index => $type)
                                    <label><input type="radio" name="scan_type" value="{{ $type[0] }}" {{ $index === 0 ? 'checked' : '' }}><span><i
                                                class="bi {{ $type[3] }}"></i><strong>{{ $type[1] }}</strong><small>{{ $type[2] }}</small></span></label>
                                @endforeach
                            </div>
                        </div>
                        <div class="d-flex justify-content-end"><button class="btn btn-primary px-6"><i class="bi bi-play-fill me-2"></i>Queue scan</button></div>
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
                    <div class="d-flex justify-content-between text-muted fs-7 mb-2"><span>Estimated utilization</span><strong
                            class="text-gray-800">{{ $scanStats['utilization'] }}%</strong></div>
                    <div class="progress h-8px">
                        <div class="progress-bar bg-primary" style="width:{{ $scanStats['utilization'] }}%"></div>
                    </div>
                    <div class="row g-3 mt-4">
                        <div class="col-6">
                            <div class="mg-mini-stat"><strong>{{ $scanStats['running'] }}</strong><span>Running</span></div>
                        </div>
                        <div class="col-6">
                            <div class="mg-mini-stat"><strong>{{ $scanStats['queued'] }}</strong><span>Queued</span></div>
                        </div>
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
                            <th>Pages</th>
                            <th>Started</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentScans as $scan)
                            <tr>
                                <td class="fw-semibold">{{ $scan->website->domain }}</td>
                                <td>{{ ucfirst($scan->type) }}</td>
                                <td><span
                                        class="badge badge-light-{{ $scan->status === 'completed' ? 'success' : ($scan->status === 'failed' ? 'danger' : 'primary') }}">{{ ucfirst($scan->status) }}</span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="progress h-6px w-100px">
                                            <div class="progress-bar bg-primary" style="width:{{ $scan->progress }}%"></div>
                                        </div><span>{{ $scan->progress }}%</span>
                                    </div>
                                </td>
                                <td>{{ $scan->pages_scanned }}</td>
                                <td class="text-muted">{{ ($scan->started_at ?? $scan->created_at)->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-10">No scans have been queued yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
