<?php

namespace App\Services;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;
use App\Detectors\DuplicateContentDetector;
use RuntimeException;

final class DetectorRegistry
{
    /** @var list<Detector>|null */
    private ?array $detectors = null;

    /** @return list<DetectorResult> */
    public function analyze(
        PageDocument $page,
        string $scanType = 'full',
        bool $includeDuplicateContent = true,
    ): array {
        $results = [];
        foreach ($this->detectors() as $detector) {
            if (! $includeDuplicateContent && $detector instanceof DuplicateContentDetector) {
                continue;
            }
            $results = array_merge($results, $this->filter($detector->detect($page), $scanType));
        }

        return $results;
    }

    /** @return array<string, true> */
    public function duplicateSketch(PageDocument $page): array
    {
        foreach ($this->detectors() as $detector) {
            if ($detector instanceof DuplicateContentDetector) {
                return $detector->sketchFor($page);
            }
        }

        return [];
    }

    /** @param array<string, true> $sketch @return list<DetectorResult> */
    public function analyzeDuplicateSketch(string $url, array $sketch, string $scanType): array
    {
        foreach ($this->detectors() as $detector) {
            if ($detector instanceof DuplicateContentDetector) {
                return $this->filter($detector->detectSketch($url, $sketch), $scanType);
            }
        }

        return [];
    }

    /** @param list<DetectorResult> $results @return list<DetectorResult> */
    public function filter(array $results, string $scanType): array
    {
        return array_values(array_filter(
            $results,
            fn (DetectorResult $result): bool => $this->included($result, $scanType)
        ));
    }

    public function warmReusablePage(PageDocument $page, string $scanType): void
    {
        if (! in_array($scanType, ['full', 'copyright', 'priority'], true)) {
            return;
        }

        foreach ($this->detectors() as $detector) {
            if ($detector instanceof DuplicateContentDetector) {
                $detector->detect($page);

                return;
            }
        }
    }

    public function resetDuplicateAnalysis(): void
    {
        foreach ($this->detectors() as $detector) {
            if ($detector instanceof DuplicateContentDetector) {
                $detector->reset();

                return;
            }
        }
    }

    /** @return list<Detector> */
    private function detectors(): array
    {
        if ($this->detectors !== null) {
            return $this->detectors;
        }

        $this->detectors = [];
        foreach ((array) config('maxguard.detectors', []) as $class) {
            $detector = app($class);
            if (! $detector instanceof Detector) {
                throw new RuntimeException("Configured detector [{$class}] must implement Detector.");
            }
            $this->detectors[] = $detector;
        }

        return $this->detectors;
    }

    private function included(DetectorResult $result, string $scanType): bool
    {
        if (str_starts_with($result->ruleKey, 'publisher.')) {
            return true;
        }

        return match ($scanType) {
            'copyright' => in_array($result->category, ['Copyright', 'Duplicate content'], true),
            'ads' => $result->category === 'Ad experience',
            'privacy' => $result->category === 'Privacy & consent',
            'priority' => in_array($result->severity, ['critical', 'high'], true),
            default => true,
        };
    }
}
