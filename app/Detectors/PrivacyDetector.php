<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class PrivacyDetector implements Detector
{
    public function key(): string
    {
        return 'privacy';
    }

    public function detect(PageDocument $page): array
    {
        if (! $page->isHomePage()) {
            return [];
        }

        $results = [];
        if (! ($page->meta['has_privacy_link'] ?? false)) {
            $results[] = new DetectorResult(
                ruleKey: 'privacy.missing-disclosure-link',
                category: 'Privacy & consent',
                severity: 'high',
                confidence: 94,
                title: 'Privacy or cookie disclosure link not found',
                summary: 'The home page did not expose a discoverable privacy or cookie disclosure link.',
                policyReference: 'Google Publisher Policies — privacy-related disclosures',
                signals: ['privacy_link_found' => false],
                remediation: ['Publish an accurate privacy policy and link it from persistent site navigation or footer.'],
            );
        }

        if ($page->adCount > 0 && ! ($page->meta['has_consent_signal'] ?? false)) {
            $results[] = new DetectorResult(
                ruleKey: 'privacy.consent-signal-missing',
                category: 'Privacy & consent',
                severity: 'review',
                confidence: 72,
                title: 'Consent management signal was not detected',
                summary: 'Ads were detected but common CMP/cookie-consent markers were not present in the initial HTML.',
                policyReference: 'Google consent requirements — implementation review',
                signals: ['ad_count' => $page->adCount, 'cmp_signal_found' => false],
                remediation: ['Verify CMP behavior in a real browser by region; server HTML alone cannot prove consent compliance.'],
            );
        }

        return $results;
    }
}

