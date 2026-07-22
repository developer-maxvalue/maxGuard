<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class AdsTxtDetector implements Detector
{
    public function key(): string
    {
        return 'ads-txt';
    }

    public function detect(PageDocument $page): array
    {
        if (! $page->isHomePage()) {
            return [];
        }

        if (! ($page->meta['ads_txt_present'] ?? false)) {
            return [new DetectorResult(
                ruleKey: 'ads-txt.missing-or-empty',
                category: 'Ad experience',
                severity: 'high',
                confidence: 98,
                title: 'ads.txt is missing or empty',
                summary: 'The crawler could not find a usable ads.txt file at the site root.',
                policyReference: 'IAB ads.txt / Google AdSense inventory authorization review',
                signals: ['http_status' => $page->meta['ads_txt_status'] ?? null, 'authorized_lines' => 0],
                remediation: ['Publish a valid ads.txt at the root domain and verify the publisher ID values.'],
            )];
        }

        if (! ($page->meta['ads_txt_has_google'] ?? false)) {
            return [new DetectorResult(
                ruleKey: 'ads-txt.google-entry-review',
                category: 'Ad experience',
                severity: 'review',
                confidence: 86,
                title: 'Google seller entry not detected in ads.txt',
                summary: 'ads.txt exists, but no line beginning with google.com was found.',
                policyReference: 'Google AdSense ads.txt authorization review',
                signals: ['authorized_lines' => $page->meta['ads_txt_lines'] ?? 0, 'google_entry' => false],
                remediation: ['Compare ads.txt with the exact publisher declaration shown in the AdSense account.'],
            )];
        }

        return [];
    }
}

