<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class AdExperienceDetector implements Detector
{
    public function key(): string
    {
        return 'ad-experience';
    }

    public function detect(PageDocument $page): array
    {
        if ($page->adCount === 0) {
            return [];
        }

        $maxAds = (int) config('maxguard.thresholds.max_ads_per_page', 6);
        $minWordsPerAd = (int) config('maxguard.thresholds.min_words_per_ad', 220);
        $ratio = (int) floor($page->wordCount / max(1, $page->adCount));

        if ($page->adCount <= $maxAds && $ratio >= $minWordsPerAd) {
            return [];
        }

        $severe = $page->adCount >= $maxAds + 3 || $ratio < 100;

        return [new DetectorResult(
            ruleKey: 'ads.density-review',
            category: 'Ad experience',
            severity: $severe ? 'high' : 'review',
            confidence: 88,
            title: 'Ad density may overwhelm page content',
            summary: "Detected {$page->adCount} ad slot(s), approximately one ad per {$ratio} readable words.",
            policyReference: 'Google Publisher Policies — more ads or paid promotional material than publisher-content',
            signals: ['ad_count' => $page->adCount, 'word_count' => $page->wordCount, 'words_per_ad' => $ratio],
            remediation: [
                'Reduce ad units and preserve a clear content-first reading experience.',
                'Review mobile viewport placement and accidental-click risk manually.',
                'Keep navigation and interactive controls visually separate from ads.',
            ],
        )];
    }
}

