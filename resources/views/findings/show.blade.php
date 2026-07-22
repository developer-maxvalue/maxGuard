@extends('layouts.app')

@section('title', $finding['id'])

@section('content')
    <div class="mg-breadcrumb">
        <a href="{{ route('findings.index') }}">Findings</a><i class="bi bi-chevron-right"></i><span>{{ $finding['id'] }}</span>
    </div>
    <div class="mg-page-heading align-items-end">
        <div>
            <div class="d-flex align-items-center flex-wrap gap-3">
                <h1 class="mb-0">{{ $finding['title'] }}</h1><x-status-badge :status="$finding['severity']" />
            </div>
            <p class="mt-2 mb-0">{{ $finding['site'] }} · Detected {{ $finding['detected'] }} · Confidence {{ $finding['confidence'] }}%</p>
        </div>
        <div class="d-flex gap-3">
            <form method="POST" action="{{ route('findings.update', $finding['id']) }}">@csrf @method('PATCH')<input type="hidden" name="status"
                    value="investigating"><button class="btn btn-light"><i class="bi bi-search me-2"></i>Investigate</button></form>
            <form method="POST" action="{{ route('findings.update', $finding['id']) }}">@csrf @method('PATCH')<input type="hidden" name="status"
                    value="remediating"><button class="btn btn-primary"><i class="bi bi-check2-circle me-2"></i>Start remediation</button></form>
        </div>
    </div>

    <div class="row g-5">
        <div class="col-xl-8">
            <div class="card mg-card mb-5">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Captured page evidence</h2>
                        <p class="mg-card-subtitle text-truncate mw-700px">{{ $finding['url'] }}</p>
                    </div>
                    <div class="card-toolbar"><span class="badge badge-light-success"><i class="bi bi-shield-check me-1"></i>Immutable snapshot</span></div>
                </div>
                <div class="card-body pt-1">
                    <div class="mg-browser-proof">
                        <div class="mg-browser-bar"><span></span><span></span><span></span>
                            <div><i class="bi bi-lock-fill"></i>{{ parse_url($finding['url'], PHP_URL_HOST) }}</div>
                        </div>
                        <div class="mg-proof-page">
                            <div class="mg-proof-nav"><strong>{{ strtoupper(parse_url($finding['url'], PHP_URL_HOST) ?: $finding['site']) }}</strong><span>CAPTURED
                                    PAGE EVIDENCE</span></div>
                            <div class="mg-proof-content">
                                <div class="mg-proof-article"><small>{{ strtoupper($finding['category']) }}</small>
                                    <h3>{{ $finding['title'] }}</h3>
                                    <p class="lead-lines"></p>
                                    <div class="mg-proof-image"><i class="bi bi-file-earmark-lock"></i><span>Immutable HTML snapshot stored privately</span></div>
                                    <p class="body-lines"></p>
                                </div>
                                <aside>
                                    <div class="mg-proof-ad">ADVERTISEMENT</div>
                                    <div class="mg-proof-ad small">ADVERTISEMENT</div>
                                </aside>
                            </div>
                            <div class="mg-evidence-highlight"><i class="bi bi-exclamation-triangle-fill"></i><span>{{ $finding['confidence'] }}% detector
                                    confidence ·
                                    review the stored signals before making a final decision</span></div>
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-3 mt-5"><span class="badge badge-light">HTML snapshot</span><span class="badge badge-light">Detector
                            signals</span><span class="badge badge-light">SHA-256 integrity</span>
                        @if ($finding['evidence']->isNotEmpty())
                            <a href="{{ route('evidence.download', $finding['evidence']->first()) }}" class="btn btn-sm btn-light-primary ms-md-auto"><i
                                    class="bi bi-download me-2"></i>Download latest evidence</a>
                        @endif
                    </div>
                </div>
            </div>

            <div class="card mg-card">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Remediation plan</h2>
                        <p class="mg-card-subtitle">Complete these actions before requesting verification.</p>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <div class="mg-action-list">
                        @foreach ($finding['actions'] as $index => $action)
                            <label><input class="form-check-input" type="checkbox"><span><strong>Step
                                        {{ $index + 1 }}</strong>{{ $action }}</span></label>
                        @endforeach
                    </div>
                    <form method="POST" action="{{ route('findings.update', $finding['id']) }}" class="text-end mt-5">@csrf @method('PATCH')<input type="hidden"
                            name="status" value="resolved"><button class="btn btn-success"><i class="bi bi-shield-check me-2"></i>Mark resolved</button></form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mg-card mb-5">
                <div class="card-body p-6">
                    <div class="d-flex align-items-start justify-content-between mb-4">
                        <div>
                            <div class="mg-eyebrow mb-2">Risk assessment</div>
                            <h2 class="mg-card-title mb-0">Why this was flagged</h2>
                        </div><span class="mg-confidence">{{ $finding['confidence'] }}%</span>
                    </div>
                    <p class="text-gray-700 lh-lg">{{ $finding['summary'] }}</p>
                    <div class="mg-policy-callout"><i class="bi bi-journal-text"></i>
                        <div><small>Policy mapping</small><strong>{{ $finding['policy'] }}</strong></div>
                    </div>
                </div>
            </div>

            <div class="card mg-card mb-5">
                <div class="card-header border-0 pt-2">
                    <div class="card-title">
                        <h2 class="mg-card-title">Detection signals</h2>
                    </div>
                </div>
                <div class="card-body pt-0">
                    @foreach ($finding['signals'] as $signal)
                        <div class="mg-signal">
                            <div><strong>{{ $signal['label'] }}</strong><small>{{ $signal['detail'] }}</small></div><span>{{ $signal['value'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card mg-card">
                <div class="card-header border-0 pt-2">
                    <div class="card-title">
                        <h2 class="mg-card-title">Evidence timeline</h2>
                    </div>
                </div>
                <div class="card-body pt-0">
                    <div class="mg-timeline">
                        @foreach ($finding['timeline'] as $event)
                            <div><span></span><time>{{ $event['time'] }}</time>
                                <section><strong>{{ $event['title'] }}</strong><small>{{ $event['detail'] }}</small></section>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
