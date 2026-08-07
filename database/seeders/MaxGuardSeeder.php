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
            ['name' => 'Tin Nóng Ngôi Sao', 'domain' => 'starhotnews.kingka.info', 'score' => 62, 'status' => 'critical', 'pages' => 1284],
            ['name' => 'Xu Hướng Hằng Ngày', 'domain' => 'dailytrendhub.com', 'score' => 74, 'status' => 'high', 'pages' => 906],
            ['name' => 'Hướng Dẫn Tài Chính', 'domain' => 'howtofinance.net', 'score' => 81, 'status' => 'review', 'pages' => 632],
            ['name' => 'Ý Tưởng Nhà Đẹp', 'domain' => 'freshhomeideas.co', 'score' => 96, 'status' => 'healthy', 'pages' => 418],
        ];

        foreach ($sites as $index => $data) {
            $website = Website::query()->updateOrCreate(['domain' => $data['domain']], [
                'user_id' => $ownerId,
                'name' => $data['name'],
                'slug' => Str::slug(str_replace('.', '-', $data['domain'])),
                'start_url' => 'https://'.$data['domain'].'/',
                'status' => $data['status'],
                'overall_score' => $data['score'],
                'pages_count' => $data['pages'],
                'last_discovered_pages' => $data['pages'],
                'last_scanned_pages' => $data['pages'],
                'last_scan_partial' => false,
                'last_scanned_at' => now()->subMinutes(8 + ($index * 42)),
                'next_scan_at' => now()->addHours(6 + $index),
            ]);

            $scan = Scan::query()->updateOrCreate([
                'website_id' => $website->id,
                'ruleset_version' => 'demo-1.0.0',
            ], [
                'type' => 'full',
                'status' => Scan::STATUS_COMPLETED,
                'progress' => 100,
                'pages_discovered' => $data['pages'],
                'pages_scanned' => $data['pages'],
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
                'title' => 'Khoảnh khắc hài đáng nhớ của Dean Martin tại London',
                'word_count' => 486,
                'ad_count' => 7,
                'last_scanned_at' => $scan->finished_at,
            ]);

            $findings = [
                ['id' => 'MG-1042', 'rule' => 'copyright.media-provenance-unverified', 'category' => 'Copyright', 'severity' => 'critical', 'confidence' => 96, 'title' => 'Bài viết sao chép và nội dung đa phương tiện chưa được cấp phép', 'summary' => 'Trang cần được xác minh thủ công về quyền sở hữu văn bản và nội dung đa phương tiện.', 'policy' => 'Chính sách dành cho nhà xuất bản của Google — lạm dụng quyền sở hữu trí tuệ'],
                ['id' => 'MG-1038', 'rule' => 'duplicate.internal-near-match', 'category' => 'Duplicate content', 'severity' => 'critical', 'confidence' => 92, 'title' => 'Nội dung có độ tương đồng đáng kể', 'summary' => 'Bài viết trùng lặp đáng kể với một trang khác đã được lập chỉ mục.', 'policy' => 'Chính sách dành cho nhà xuất bản của Google — xem xét nội dung giá trị thấp hoặc tái sử dụng'],
                ['id' => 'MG-1019', 'rule' => 'ads.density-review', 'category' => 'Ad experience', 'severity' => 'review', 'confidence' => 79, 'title' => 'Mật độ quảng cáo trên thiết bị di động vượt ngưỡng', 'summary' => 'Tỷ lệ nội dung trên quảng cáo cần được kiểm tra thủ công trên thiết bị di động.', 'policy' => 'Chính sách dành cho nhà xuất bản của Google — xem xét vị trí quảng cáo'],
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
                    'remediation' => ['Xem xét bằng chứng.', 'Xác minh quyền sử dụng hoặc xóa nội dung bị ảnh hưởng.', 'Chạy lượt quét theo dõi.'],
                    'first_seen_at' => $scan->finished_at,
                    'last_seen_at' => $scan->finished_at,
                ]);
            }

            $website->update(['open_findings_count' => count($findings)]);
            $scan->update(['findings_count' => count($findings)]);
        }
    }
}
