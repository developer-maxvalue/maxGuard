<?php

namespace App\Support;

use Illuminate\Support\Arr;

final class DemoData
{
    public static function metrics(): array
    {
        return [
            ['label' => 'Total sites', 'value' => '24', 'note' => '21 monitored', 'tone' => 'primary', 'icon' => 'bi-globe2'],
            ['label' => 'Compliance score', 'value' => '87', 'note' => '4.2% higher this month', 'tone' => 'success', 'icon' => 'bi-shield-check'],
            ['label' => 'Critical issues', 'value' => '7', 'note' => '3 require action today', 'tone' => 'danger', 'icon' => 'bi-exclamation-triangle'],
            ['label' => 'Protected revenue', 'value' => '$48.2K', 'note' => 'estimated monthly', 'tone' => 'info', 'icon' => 'bi-currency-dollar'],
        ];
    }

    public static function trend(): array
    {
        return [43, 48, 47, 56, 55, 63, 62, 70, 68, 76, 75, 82, 81, 88, 87, 94];
    }

    public static function sites(): array
    {
        return [
            [
                'slug' => 'starhotnews-kingka-info',
                'domain' => 'starhotnews.kingka.info',
                'score' => 62,
                'status' => 'critical',
                'top_risk' => 'Copyright / reused content',
                'findings' => 14,
                'last_scan' => '8 min ago',
                'pages' => 1284,
                'coverage' => 98.4,
                'revenue_risk' => '$12.8K/mo',
            ],
            [
                'slug' => 'dailytrendhub-com',
                'domain' => 'dailytrendhub.com',
                'score' => 74,
                'status' => 'high',
                'top_risk' => 'Invalid traffic pattern',
                'findings' => 8,
                'last_scan' => '36 min ago',
                'pages' => 906,
                'coverage' => 94.7,
                'revenue_risk' => '$8.4K/mo',
            ],
            [
                'slug' => 'howtofinance-net',
                'domain' => 'howtofinance.net',
                'score' => 81,
                'status' => 'review',
                'top_risk' => 'Ad density on mobile',
                'findings' => 5,
                'last_scan' => '2 hours ago',
                'pages' => 632,
                'coverage' => 96.1,
                'revenue_risk' => '$3.1K/mo',
            ],
            [
                'slug' => 'freshhomeideas-co',
                'domain' => 'freshhomeideas.co',
                'score' => 96,
                'status' => 'healthy',
                'top_risk' => 'No blocking issues',
                'findings' => 1,
                'last_scan' => 'Yesterday',
                'pages' => 418,
                'coverage' => 99.8,
                'revenue_risk' => '$0',
            ],
        ];
    }

    public static function site(string $slug): array
    {
        $site = Arr::first(self::sites(), fn (array $site) => $site['slug'] === $slug);
        abort_unless($site, 404);

        return array_merge($site, [
            'policies' => [
                ['name' => 'Copyright & duplicate', 'score' => 46, 'count' => '14 findings', 'status' => 'critical'],
                ['name' => 'Content quality', 'score' => 58, 'count' => '22 pages', 'status' => 'high'],
                ['name' => 'Ad experience', 'score' => 78, 'count' => '5 findings', 'status' => 'review'],
                ['name' => 'Privacy & consent', 'score' => 91, 'count' => '1 advisory', 'status' => 'healthy'],
            ],
            'risky_urls' => [
                ['finding_id' => 'MG-1042', 'path' => '/qu-dean-martins-unforgettable-london-comedy…', 'issue' => 'Copied article & unlicensed media', 'severity' => 'critical', 'evidence' => 12],
                ['finding_id' => 'MG-1038', 'path' => '/celebrity-news/classic-hollywood-moments…', 'issue' => 'Substantial content similarity', 'severity' => 'critical', 'evidence' => 8],
                ['finding_id' => 'MG-1027', 'path' => '/news/viral-video-collection-july…', 'issue' => 'Embedded video rights unclear', 'severity' => 'high', 'evidence' => 5],
                ['finding_id' => 'MG-1019', 'path' => '/entertainment/retro-comedy-night…', 'issue' => 'Thin editorial value / ad-heavy', 'severity' => 'review', 'evidence' => 3],
            ],
        ]);
    }

    public static function findings(): array
    {
        return [
            ['id' => 'MG-1042', 'site' => 'starhotnews.kingka.info', 'title' => 'Copied article and unlicensed media', 'category' => 'Copyright', 'severity' => 'critical', 'confidence' => 96, 'affected' => '1 URL', 'detected' => '8 min ago', 'status' => 'open'],
            ['id' => 'MG-1038', 'site' => 'starhotnews.kingka.info', 'title' => 'Substantial content similarity', 'category' => 'Duplicate content', 'severity' => 'critical', 'confidence' => 92, 'affected' => '7 URLs', 'detected' => '12 min ago', 'status' => 'open'],
            ['id' => 'MG-1031', 'site' => 'dailytrendhub.com', 'title' => 'Suspicious direct traffic concentration', 'category' => 'Invalid traffic', 'severity' => 'high', 'confidence' => 88, 'affected' => 'Site-wide', 'detected' => '36 min ago', 'status' => 'investigating'],
            ['id' => 'MG-1027', 'site' => 'starhotnews.kingka.info', 'title' => 'Embedded video rights unclear', 'category' => 'Copyright', 'severity' => 'high', 'confidence' => 84, 'affected' => '3 URLs', 'detected' => '1 hour ago', 'status' => 'open'],
            ['id' => 'MG-1019', 'site' => 'howtofinance.net', 'title' => 'Mobile ad density exceeds threshold', 'category' => 'Ad experience', 'severity' => 'review', 'confidence' => 79, 'affected' => '5 URLs', 'detected' => '2 hours ago', 'status' => 'open'],
        ];
    }

    public static function finding(string $id): array
    {
        $finding = Arr::first(self::findings(), fn (array $finding) => $finding['id'] === $id);
        abort_unless($finding, 404);

        return array_merge($finding, [
            'url' => 'https://starhotnews.kingka.info/qu-dean-martins-unforgettable-london-comedy-moment-that-broke-everyone-into-laughter/',
            'policy' => 'Google Publisher Policies — Intellectual property abuse',
            'summary' => 'The page closely reproduces a third-party article structure and media without clear ownership, license evidence, or material original reporting.',
            'signals' => [
                ['label' => 'Text similarity', 'value' => '91%', 'detail' => 'Matched against 3 earlier indexed sources'],
                ['label' => 'Image provenance', 'value' => 'Unverified', 'detail' => 'No license, attribution or original asset metadata'],
                ['label' => 'Original contribution', 'value' => 'Low', 'detail' => 'Limited commentary beyond the reproduced source'],
            ],
            'actions' => [
                'Unpublish the page until content and media rights are verified.',
                'Replace copied passages with original reporting, analysis, or first-hand commentary.',
                'Use licensed or owned images and keep invoices, contracts, or permission records.',
                'Request a fresh scan and attach the remediation evidence before closing the case.',
            ],
            'timeline' => [
                ['time' => '10:42', 'title' => 'Finding created', 'detail' => 'Automated copyright similarity scan completed.'],
                ['time' => '10:43', 'title' => 'Evidence captured', 'detail' => 'HTML snapshot, rendered page and source matches stored.'],
                ['time' => '10:45', 'title' => 'Risk score calculated', 'detail' => 'Severity critical · confidence 96%.'],
            ],
        ]);
    }
}

