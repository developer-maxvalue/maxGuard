@props(['status'])

@php
    $map = [
        'critical' => ['Critical', 'danger'],
        'high' => ['High', 'warning'],
        'review' => ['Review', 'info'],
        'healthy' => ['Healthy', 'success'],
        'open' => ['Open', 'danger'],
        'investigating' => ['Investigating', 'warning'],
        'resolved' => ['Resolved', 'success'],
        'queued' => ['Queued', 'info'],
        'running' => ['Running', 'info'],
        'completed' => ['Completed', 'success'],
        'partial' => ['Partial', 'warning'],
        'failed' => ['Failed', 'danger'],
        'cancelled' => ['Cancelled', 'secondary'],
    ];
    [$label, $tone] = $map[$status] ?? [ucfirst($status), 'secondary'];
@endphp

<span class="badge mg-status mg-status-{{ $tone }}">{{ $label }}</span>
