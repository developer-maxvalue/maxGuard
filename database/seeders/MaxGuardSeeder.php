<?php

namespace Database\Seeders;

use App\Models\Finding;
use App\Models\Page;
use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class MaxGuardSeeder extends Seeder
{
    public function run(): void
    {
        $ownerId = User::query()->value('id');

        $sites = [
            ['name' => 'Star Hot News', 'domain' => 'starhotnews.kingka.info', 'score' => 62, 'status' => 'critical', 'revenue' => 12800, 'pages' => 1284],
            ['name' => 'Daily Trend Hub', 'domain' => 'dailytrendhub.com', 'score' => 74, 'status' => 'high', 'revenue' => 8400, 'pages' => 906],
            ['name' => 'How To Finance', 'domain' => 'howtofinance.net', 'score' => 81, 'status' => 'review', 'revenue' => 3100, 'pages' => 632],
            ['name' => 'Fresh Home Ideas', 'domain' => 'freshhomeideas.co', 'score' => 96, 'status' => 'healthy', 'revenue' => 1800, 'pages' => 418],
        ];

        foreach ($sites as $index => $data) {
            $website = Website::query()->updateOrCreate(['domain' => $data['domain']], [
                'user_id' => $ownerId,
                'name' => $data['name'],
                'slug' => Str::slug(str_replace('.', '-', $data['domain'])),
                'start_url' => 'https://'.$data['domain'].'/',
                'status' => $data['status'],
                'overall_score' => $data['score'],
                'expected_monthly_revenue' => $data['revenue'],
                'pages_count' => $data['pages'],
                'last_scanned_at' => now()->subMinutes(8 + ($index * 42)),
                'next_scan_at' => now()->addHours(6 + $index),
                'ownership_verified_at' => now()->subDays(30),
            ]);

            $scan = Scan::query()->updateOrCreate([
                'website_id' => $website->id,
                'ruleset_version' => 'demo-1.0.0',
            ], [
                'type' => 'full',
                'status' => Scan::STATUS_COMPLETED,
                'progress' => 100,
                'pages_discovered' => min($data['pages'], 100),
                'pages_scanned' => min($data['pages'], 100),
                'score' => $data['score'],
                'started_at' => now()->subMinutes(25 + ($index * 42)),
                'finished_at' => now()->subMinutes(8 + ($index * 42)),
            ]);

            if ($index !== 0) {
                continue;
            }

            $page = Page::query()->updateOrCreate([
                'website_id' => $website->id,
                'url_hash' => hash('sha256', $website->start_url.'qu-dean-martins-unforgettable-london-comedy-moment-that-broke-everyone-into-laughter/'),
            ], [
                'last_scan_id' => $scan->id,
                'url' => $website->start_url.'qu-dean-martins-unforgettable-london-comedy-moment-that-broke-everyone-into-laughter/',
                'status_code' => 200,
                'title' => "Dean Martin's unforgettable London comedy moment",
                'word_count' => 486,
                'ad_count' => 7,
                'last_scanned_at' => $scan->finished_at,
            ]);

            $findings = [
                ['id' => 'MG-1042', 'rule' => 'copyright.media-provenance-unverified', 'category' => 'Copyright', 'severity' => 'critical', 'confidence' => 96, 'title' => 'Copied article and unlicensed media', 'summary' => 'The page requires manual verification of text and media ownership.', 'policy' => 'Google Publisher Policies — intellectual property abuse'],
                ['id' => 'MG-1038', 'rule' => 'duplicate.internal-near-match', 'category' => 'Duplicate content', 'severity' => 'critical', 'confidence' => 92, 'title' => 'Substantial content similarity', 'summary' => 'The article substantially overlaps another indexed page.', 'policy' => 'Google Publisher Policies — low-value/reused inventory review'],
                ['id' => 'MG-1019', 'rule' => 'ads.density-review', 'category' => 'Ad experience', 'severity' => 'review', 'confidence' => 79, 'title' => 'Mobile ad density exceeds threshold', 'summary' => 'The content-to-ad ratio requires a manual mobile review.', 'policy' => 'Google Publisher Policies — ad placement review'],
            ];

            foreach ($findings as $findingData) {
                Finding::query()->updateOrCreate([
                    'website_id' => $website->id,
                    'fingerprint' => hash('sha256', $findingData['rule'].'|'.$page->url),
                ], [
                    'public_id' => $findingData['id'],
                    'scan_id' => $scan->id,
                    'page_id' => $page->id,
                    'rule_key' => $findingData['rule'],
                    'category' => $findingData['category'],
                    'severity' => $findingData['severity'],
                    'confidence' => $findingData['confidence'],
                    'status' => 'open',
                    'title' => $findingData['title'],
                    'summary' => $findingData['summary'],
                    'policy_reference' => $findingData['policy'],
                    'signals' => ['demo_evidence' => true, 'manual_review_required' => true],
                    'remediation' => ['Review the evidence.', 'Verify rights or remove the affected material.', 'Run a verification scan.'],
                    'first_seen_at' => $scan->finished_at,
                    'last_seen_at' => $scan->finished_at,
                ]);
            }

            $website->update(['open_findings_count' => count($findings)]);
            $scan->update(['findings_count' => count($findings)]);
        }
    }
}
