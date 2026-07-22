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
    ];
    [$label, $tone] = $map[$status] ?? [ucfirst($status), 'secondary'];
@endphp

<span class="badge mg-status mg-status-{{ $tone }}">{{ $label }}</span>
