<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class TechnicalTrustDetector implements Detector
{
    public function key(): string
    {
        return 'technical-trust';
    }

    public function detect(PageDocument $page): array
    {
        $results = [];

        if (parse_url($page->url, PHP_URL_SCHEME) !== 'https') {
            $results[] = new DetectorResult(
                ruleKey: 'technical.no-https',
                category: 'Technical trust',
                severity: 'high',
                confidence: 100,
                title: 'Page is not served over HTTPS',
                summary: 'The scanned page uses an unencrypted HTTP connection.',
                signals: ['scheme' => parse_url($page->url, PHP_URL_SCHEME)],
                remediation: ['Enable HTTPS site-wide and redirect HTTP URLs to their HTTPS equivalent.'],
            );
        }

        if ($page->isHomePage() && (! ($page->meta['has_about_link'] ?? false) || ! ($page->meta['has_contact_link'] ?? false))) {
            $results[] = new DetectorResult(
                ruleKey: 'trust.publisher-identity-links',
                category: 'Content quality',
                severity: 'review',
                confidence: 84,
                title: 'Publisher identity links are incomplete',
                summary: 'An About or Contact link was not clearly discoverable from the home page.',
                signals: ['about_link' => $page->meta['has_about_link'] ?? false, 'contact_link' => $page->meta['has_contact_link'] ?? false],
                remediation: ['Add clear About, Contact, ownership and editorial responsibility information.'],
            );
        }

        return $results;
    }
}

