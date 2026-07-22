<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class ContentQualityDetector implements Detector
{
    public function key(): string
    {
        return 'content-quality';
    }

    public function detect(PageDocument $page): array
    {
        $results = [];
        $thin = (int) config('maxguard.thresholds.thin_content_words', 300);
        $lowValue = (int) config('maxguard.thresholds.low_value_words', 600);

        if ($page->wordCount < $thin) {
            $results[] = new DetectorResult(
                ruleKey: 'content.thin-page',
                category: 'Content quality',
                severity: $page->wordCount < 150 ? 'high' : 'review',
                confidence: 92,
                title: 'Thin or insufficient page content',
                summary: "Only {$page->wordCount} readable words were found. Pages with little original editorial value may be unsuitable for monetization.",
                policyReference: 'Google Publisher Policies — low-value or insufficient content review',
                signals: ['word_count' => $page->wordCount, 'paragraph_count' => $page->meta['paragraph_count'] ?? 0],
                remediation: [
                    'Add substantial original reporting, analysis or first-hand expertise.',
                    'Remove the page from monetization if it exists only to host ads or embeds.',
                    'Review navigation and ensure the page has a clear purpose for users.',
                ],
            );
        } elseif ($page->wordCount < $lowValue && ($page->meta['paragraph_count'] ?? 0) < 3) {
            $results[] = new DetectorResult(
                ruleKey: 'content.low-editorial-structure',
                category: 'Content quality',
                severity: 'review',
                confidence: 76,
                title: 'Limited editorial structure',
                summary: 'The page has enough raw text but very little structured editorial content.',
                policyReference: 'Google Publisher Policies — valuable inventory review',
                signals: ['word_count' => $page->wordCount, 'paragraph_count' => $page->meta['paragraph_count'] ?? 0],
                remediation: ['Improve article structure, sourcing, author context and user-focused explanation.'],
            );
        }

        if ($page->title === '' || $page->h1Count !== 1) {
            $results[] = new DetectorResult(
                ruleKey: 'content.document-structure',
                category: 'Content quality',
                severity: 'info',
                confidence: 98,
                title: 'Page title or heading structure needs review',
                summary: 'A clear title and one primary heading help users and reviewers understand the page purpose.',
                signals: ['title_present' => $page->title !== '', 'h1_count' => $page->h1Count],
                remediation: ['Add a descriptive document title and one primary H1 heading.'],
            );
        }

        return $results;
    }
}

