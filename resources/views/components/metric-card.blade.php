@props(['label', 'value', 'note', 'tone' => 'primary', 'icon' => 'bi-activity'])

<div class="card mg-card h-100">
    <div class="card-body p-5 p-xl-6">
        <div class="d-flex align-items-start justify-content-between">
            <div>
                <div class="mg-eyebrow mb-3">{{ $label }}</div>
                <div class="mg-metric-value">{{ $value }}</div>
            </div>
            <span class="mg-icon-box mg-icon-box-{{ $tone }}"><i class="bi {{ $icon }}"></i></span>
        </div>
        <div class="mg-metric-note text-{{ $tone }} mt-5">{{ $note }}</div>
    </div>
</div>
