<?php

namespace App\Services;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;
use RuntimeException;

final class DetectorRegistry
{
    /** @var list<Detector>|null */
    private ?array $detectors = null;

    /** @return list<DetectorResult> */
    public function analyze(PageDocument $page, string $scanType = 'full'): array
    {
        $results = [];
        foreach ($this->detectors() as $detector) {
            foreach ($detector->detect($page) as $result) {
                if ($this->included($result, $scanType)) {
                    $results[] = $result;
                }
            }
        }

        return $results;
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
        return match ($scanType) {
            'copyright' => in_array($result->category, ['Copyright', 'Duplicate content'], true),
            'ads' => $result->category === 'Ad experience',
            'privacy' => $result->category === 'Privacy & consent',
            'priority' => in_array($result->severity, ['critical', 'high'], true),
            default => true,
        };
    }
}

