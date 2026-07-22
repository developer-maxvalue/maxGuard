<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class DuplicateContentDetector implements Detector
{
    /** @var list<array{url: string, shingles: array<string, true>}> */
    private array $seen = [];

    public function key(): string
    {
        return 'duplicate-content';
    }

    public function detect(PageDocument $page): array
    {
        if ($page->wordCount < 150) {
            return [];
        }

        $shingles = $this->shingles($page->normalizedText());
        $best = 0.0;
        $matchedUrl = null;

        foreach ($this->seen as $candidate) {
            $score = $this->jaccard($shingles, $candidate['shingles']);
            if ($score > $best) {
                $best = $score;
                $matchedUrl = $candidate['url'];
            }
        }

        $this->seen[] = ['url' => $page->url, 'shingles' => $shingles];
        $threshold = (float) config('maxguard.thresholds.duplicate_similarity', 0.86);
        if ($matchedUrl === null || $best < $threshold) {
            return [];
        }

        $percent = (int) round($best * 100);

        return [new DetectorResult(
            ruleKey: 'duplicate.internal-near-match',
            category: 'Duplicate content',
            severity: $best >= 0.95 ? 'high' : 'review',
            confidence: $percent,
            title: 'Substantial internal content similarity',
            summary: "This page shares approximately {$percent}% of its four-word shingles with another page on the same site.",
            policyReference: 'Google Publisher Policies — low-value/reused inventory review',
            signals: ['similarity' => $percent, 'matched_url' => $matchedUrl, 'method' => '4-word Jaccard shingles'],
            remediation: [
                'Consolidate equivalent pages and use a canonical URL where appropriate.',
                'Add distinct original value rather than changing only the headline or introduction.',
                'Do not treat this signal alone as proof of copyright infringement.',
            ],
            fingerprintSalt: hash('sha256', $matchedUrl),
        )];
    }

    /** @return array<string, true> */
    private function shingles(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $set = [];
        $limit = min(count($words) - 3, 1500);
        for ($index = 0; $index < $limit; $index++) {
            $set[implode(' ', array_slice($words, $index, 4))] = true;
        }

        return $set;
    }

    /** @param array<string, true> $first @param array<string, true> $second */
    private function jaccard(array $first, array $second): float
    {
        if ($first === [] || $second === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($first, $second));
        $union = count($first) + count($second) - $intersection;

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}

