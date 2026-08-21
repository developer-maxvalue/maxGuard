<?php

namespace Tests\Feature;

use App\Models\Finding;
use App\Models\Page;
use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebsiteUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_detail_shows_scan_debug_full_categories_and_policy_before_ai(): void
    {
        $user = User::factory()->create();
        $website = $this->website($user);
        $scan = $website->scans()->create([
            'status' => Scan::STATUS_RUNNING,
            'type' => 'full',
            'progress' => 42,
            'pages_scanned' => 42,
            'pages_discovered' => 100,
            'current_url' => 'https://publisher.example/article-42',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', $website));

        $response->assertOk()
            ->assertSee('Debug đang quét')
            ->assertSee(route('scans.show', $scan), false)
            ->assertSee('https://publisher.example/article-42')
            ->assertSee('Bản quyền')
            ->assertSee('Nội dung trùng lặp')
            ->assertSee('Thông tin nhà xuất bản')
            ->assertSeeInOrder(['Phân tích tình trạng chính sách', 'Nhận định tổng hợp từ AI']);
    }

    public function test_owner_can_filter_site_findings_through_json_endpoint(): void
    {
        $user = User::factory()->create();
        $website = $this->website($user);
        $scan = $website->scans()->create(['status' => Scan::STATUS_COMPLETED, 'type' => 'full']);
        $page = Page::query()->create([
            'website_id' => $website->id,
            'last_scan_id' => $scan->id,
            'url' => 'https://publisher.example/privacy',
            'url_hash' => hash('sha256', 'https://publisher.example/privacy'),
        ]);
        Finding::query()->create([
            'website_id' => $website->id,
            'scan_id' => $scan->id,
            'page_id' => $page->id,
            'fingerprint' => hash('sha256', 'privacy.api-filter'),
            'rule_key' => 'privacy.api-filter',
            'category' => 'Privacy & consent',
            'severity' => 'high',
            'confidence' => 93,
            'status' => 'open',
            'title' => 'Privacy disclosure missing',
            'summary' => 'Privacy policy link was not found.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        Finding::query()->create([
            'website_id' => $website->id,
            'scan_id' => $scan->id,
            'fingerprint' => hash('sha256', 'ads.api-filter'),
            'rule_key' => 'ads.api-filter',
            'category' => 'Ad experience',
            'severity' => 'review',
            'confidence' => 70,
            'status' => 'open',
            'title' => 'Ad density may overwhelm page content',
            'summary' => 'The ad-to-content ratio needs review.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson(route('sites.findings', ['site' => $website, 'severity' => 'high', 'category' => 'Privacy & consent']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.category', 'Quyền riêng tư và đồng ý')
            ->assertJsonPath('data.0.severity', 'high')
            ->assertJsonPath('meta.total', 1);

        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)
            ->getJson(route('sites.findings', $website))
            ->assertForbidden();
    }

    public function test_evidence_page_hides_manual_actions_and_links_to_google_policy(): void
    {
        $user = User::factory()->create();
        $website = $this->website($user);
        $scan = $website->scans()->create(['status' => Scan::STATUS_COMPLETED, 'type' => 'full']);
        $page = Page::query()->create([
            'website_id' => $website->id,
            'last_scan_id' => $scan->id,
            'url' => 'https://publisher.example/article',
            'url_hash' => hash('sha256', 'https://publisher.example/article'),
        ]);
        $finding = Finding::query()->create([
            'website_id' => $website->id,
            'scan_id' => $scan->id,
            'page_id' => $page->id,
            'fingerprint' => hash('sha256', 'privacy.test'),
            'rule_key' => 'privacy.test',
            'category' => 'Privacy & consent',
            'severity' => 'high',
            'confidence' => 90,
            'status' => 'open',
            'title' => 'Privacy disclosure missing',
            'summary' => 'Privacy policy link was not found.',
            'policy_reference' => 'Google Publisher Policies — privacy disclosures',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($user)->get(route('findings.show', $finding))
            ->assertOk()
            ->assertSee('Xem chính sách chính thức của Google')
            ->assertSee('https://support.google.com/adsense/answer/1348695?hl=vi', false)
            ->assertDontSee('Kiểm tra bản quyền thủ công trên Google')
            ->assertDontSee('Điều tra')
            ->assertDontSee('Bắt đầu khắc phục')
            ->assertDontSee('Đánh dấu đã xử lý');
    }

    private function website(User $user): Website
    {
        return Website::query()->create([
            'user_id' => $user->id,
            'name' => 'Publisher',
            'slug' => 'publisher-example',
            'domain' => 'publisher.example',
            'start_url' => 'https://publisher.example/',
        ]);
    }
}
