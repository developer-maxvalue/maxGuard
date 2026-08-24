@php
    $sectionReferences = $section === null
        ? $aiPolicyReferences->unique(fn ($reference) => ($reference['issue'] ?? '').'|'.($reference['policy_url'] ?? ''))->values()
        : $aiPolicyReferences->where('section', $section)->values();
@endphp

@if ($sectionReferences->isNotEmpty())
    <div class="border rounded p-4 mb-4">
        <span class="d-block text-muted fs-9 fw-semibold mb-2">Chính sách AdSense/Google liên quan</span>
        <div class="d-flex flex-column gap-2">
            @foreach ($sectionReferences as $reference)
                <div>
                    <a class="fw-semibold fs-8" href="{{ $reference['policy_url'] }}" target="_blank" rel="noopener noreferrer">
                        {{ $reference['issue'] }} <i class="bi bi-box-arrow-up-right ms-1"></i>
                    </a>
                    @if (!empty($reference['policy_title']))
                        <span class="d-block fs-9 text-muted">{{ $reference['policy_title'] }}</span>
                    @endif
                    <span class="d-block text-gray-700 fs-9 mt-1">{{ $reference['relevance'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif
