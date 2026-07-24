<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class DuplicateContentDetector implements Detector
{
    /** @var list<array{url: string, sketch: array<string, true>}> */
    private array $seen = [];

    /** @var array<string, list<int>> */
    private array $candidateIndex = [];

    public function key(): string
    {
        return 'duplicate-content';
    }

    public function reset(): void
    {
        $this->seen = [];
        $this->candidateIndex = [];
    }

    public function detect(PageDocument $page): array
    {
        if ($page->wordCount < 150) {
            return [];
        }

        return $this->detectSketch($page->url, $this->sketchFor($page));
    }

    /** @return array<string, true> */
    public function sketchFor(PageDocument $page): array
    {
        if ($page->wordCount < 150) {
            return [];
        }

        return $this->sketch($page->normalizedText());
    }

    /** @param array<string, true> $sketch @return list<DetectorResult> */
    public function detectSketch(string $url, array $sketch): array
    {
        if ($sketch === []) {
            return [];
        }

        $candidates = $this->candidates($sketch);
        $best = 0.0;
        $matchedUrl = null;

        foreach ($candidates as $index) {
            $candidate = $this->seen[$index];
            $score = $this->jaccard($sketch, $candidate['sketch']);
            if ($score > $best) {
                $best = $score;
                $matchedUrl = $candidate['url'];
            }
        }

        $this->remember($url, $sketch);
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
            summary: "This page has an estimated {$percent}% four-word shingle similarity with another page on the same site.",
            policyReference: 'Google Publisher Policies — low-value/reused inventory review',
            signals: ['similarity' => $percent, 'matched_url' => $matchedUrl, 'method' => 'bottom-k sketch of 4-word shingles'],
            remediation: [
                'Consolidate equivalent pages and use a canonical URL where appropriate.',
                'Add distinct original value rather than changing only the headline or introduction.',
                'Do not treat this signal alone as proof of copyright infringement.',
            ],
            fingerprintSalt: hash('sha256', $matchedUrl),
        )];
    }

    /** @return array<string, true> */
    private function sketch(string $text): array
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $hashes = [];
        $limit = min(max(0, count($words) - 3), 3000);
        for ($index = 0; $index < $limit; $index++) {
            $shingle = implode(' ', array_slice($words, $index, 4));
            $hashes[substr(hash('sha1', $shingle), 0, 16)] = true;
        }

        ksort($hashes, SORT_STRING);
        $size = max(32, (int) config('maxguard.thresholds.duplicate_sketch_size', 128));

        return array_slice($hashes, 0, $size, true);
    }

    /** @param array<string, true> $sketch @return list<int> */
    private function candidates(array $sketch): array
    {
        $counts = [];
        foreach ($sketch as $hash => $_) {
            foreach ($this->candidateIndex[$hash] ?? [] as $index) {
                $counts[$index] = ($counts[$index] ?? 0) + 1;
            }
        }

        arsort($counts, SORT_NUMERIC);
        $limit = max(1, (int) config('maxguard.thresholds.duplicate_candidate_limit', 30));

        return array_map('intval', array_slice(array_keys($counts), 0, $limit));
    }

    /** @param array<string, true> $sketch */
    private function remember(string $url, array $sketch): void
    {
        $index = count($this->seen);
        $this->seen[] = ['url' => $url, 'sketch' => $sketch];
        $bucketLimit = max(10, (int) config('maxguard.thresholds.duplicate_bucket_limit', 200));

        foreach ($sketch as $hash => $_) {
            $this->candidateIndex[$hash] ??= [];
            $this->candidateIndex[$hash][] = $index;
            if (count($this->candidateIndex[$hash]) > $bucketLimit) {
                array_shift($this->candidateIndex[$hash]);
            }
        }
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
