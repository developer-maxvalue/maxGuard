@props(['score', 'size' => 112])

@php
    $tone = $score < 70 ? '#dc2626' : ($score < 85 ? '#d97706' : '#16a34a');
@endphp

<div class="mg-score-ring"
    style="--score: {{ $score }}; --score-color: {{ $tone }}; --ring-size: {{ $size }}px"
    role="img" aria-label="Score {{ $score }} out of 100">
    <div>
        <strong>{{ $score }}</strong>
        <small>/100</small>
    </div>
</div>
