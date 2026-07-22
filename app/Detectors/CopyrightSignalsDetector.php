<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class CopyrightSignalsDetector implements Detector
{
    public function key(): string
    {
        return 'copyright-signals';
    }

    public function detect(PageDocument $page): array
    {
        $external = (int) ($page->meta['external_images'] ?? 0);
        if ($external === 0) {
            return [];
        }

        $hasAttribution = preg_match('/\b(source|credit|courtesy|licensed?|photo by|©)\b/iu', $page->text) === 1;
        if ($hasAttribution) {
            return [];
        }

        return [new DetectorResult(
            ruleKey: 'copyright.media-provenance-unverified',
            category: 'Copyright',
            severity: $external >= 3 ? 'high' : 'review',
            confidence: $external >= 3 ? 82 : 68,
            title: 'External media provenance is unverified',
            summary: "{$external} externally hosted image(s) were found without visible licensing or attribution signals. This is a review signal, not a legal conclusion.",
            policyReference: 'Google Publisher Policies — intellectual property abuse',
            signals: ['external_images' => $external, 'visible_attribution' => false],
            remediation: [
                'Verify ownership, license or written permission for every media asset.',
                'Store contracts, invoices or permission evidence with the remediation case.',
                'Replace unverified media with owned or properly licensed alternatives.',
            ],
        )];
    }
}

