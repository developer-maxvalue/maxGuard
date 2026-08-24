<?php

namespace Tests\Feature;

use App\Models\Finding;
use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebsiteAiAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_generate_and_view_an_ai_assessment_from_latest_scan_data(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'openai_compatible',
            'maxguard.ai.api_key' => 'test-key',
            'maxguard.ai.base_url' => 'https://ai.example.test/v1',
            'maxguard.ai.model' => 'test-model',
            'maxguard.ai.output_language' => 'Vietnamese',
        ]);
        Http::fake([
            'https://ai.example.test/v1/chat/completions' => Http::response([
                'id' => 'review-1',
                'choices' => [['message' => ['content' => json_encode([
                    'risk_level' => 'high',
                    'headline' => 'Website cần xử lý sớm hai nhóm vấn đề',
                    'summary' => 'Điểm thấp chủ yếu đến từ trải nghiệm quảng cáo và chất lượng nội dung.',
                    'content_overview' => 'Tổng quan chất lượng nội dung trên toàn website.',
                    'transparency_overview' => 'Chưa đủ tín hiệu về danh tính và tính minh bạch của nhà xuất bản.',
                    'adsense_requirements_overview' => 'Cần hoàn thiện disclosure quyền riêng tư theo yêu cầu AdSense.',
                    'policy_overview' => 'Dữ liệu quét ghi nhận rủi ro chính sách cần xử lý.',
                    'policy_references' => [[
                        'section' => 'content_overview',
                        'issue' => 'Nội dung trùng lặp',
                        'relevance' => 'Nội dung sao chép cần có giá trị gia tăng độc lập.',
                        'policy_url' => 'https://support.google.com/publisherpolicies/answer/11190248?hl=vi',
                    ]],
                    'key_issues' => [[
                        'title' => 'Mật độ quảng cáo cao',
                        'severity' => 'high',
                        'why_it_matters' => 'Có thể làm giảm giá trị nội dung và gây nhấp nhầm.',
                        'evidence' => 'Finding MG-TEST có confidence 91%.',
                        'recommendation' => 'Giảm số vị trí quảng cáo và quét lại.',
                    ]],
                    'priorities' => ['Xử lý finding mức cao trước.'],
                    'limitations' => ['Lượt quét chỉ bao phủ 80% URL phát hiện.'],
                ], JSON_UNESCAPED_UNICODE)]]],
            ]),
        ]);

        $owner = User::factory()->create();
        $website = Website::query()->create([
            'user_id' => $owner->id,
            'name' => 'Publisher',
            'slug' => 'publisher-example',
            'domain' => 'publisher.example',
            'start_url' => 'https://publisher.example/',
            'status' => 'high',
            'overall_score' => 72,
            'last_discovered_pages' => 10,
            'last_scanned_pages' => 8,
            'last_scan_partial' => true,
            'open_findings_count' => 1,
            'last_scanned_at' => now(),
        ]);
        $scan = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_PARTIAL,
            'progress' => 100,
            'pages_discovered' => 10,
            'pages_scanned' => 8,
            'score' => 72,
            'finished_at' => now(),
        ]);
        Finding::query()->create([
            'public_id' => 'MG-TEST',
            'website_id' => $website->id,
            'scan_id' => $scan->id,
            'fingerprint' => hash('sha256', 'ads.test'),
            'rule_key' => 'ads.test',
            'category' => 'Ad experience',
            'severity' => 'high',
            'confidence' => 91,
            'status' => 'open',
            'title' => 'Mật độ quảng cáo cao',
            'summary' => 'Nhiều vị trí quảng cáo chen giữa nội dung.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($owner)
            ->post(route('sites.ai-assessment', $website))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $scan->refresh();
        $this->assertSame('high', data_get($scan->ai_assessment, 'risk_level'));
        $this->assertNotNull($scan->ai_assessed_at);

        $this->actingAs($owner)
            ->get(route('sites.show', $website))
            ->assertOk()
            ->assertSee('Nhận định tổng hợp từ AI')
            ->assertSee('Tính trung thực và minh bạch của nhà xuất bản')
            ->assertSee('Đối chiếu yêu cầu AdSense')
            ->assertSee('Chính sách AdSense/Google liên quan')
            ->assertSeeInOrder([
                'Tổng quan nội dung và cấu trúc',
                'https://support.google.com/publisherpolicies/answer/11190248?hl=vi',
                'Tính trung thực và minh bạch của nhà xuất bản',
            ], false);

        Http::assertSent(fn ($request): bool => str_contains($request->body(), 'MG-TEST')
            && str_contains($request->body(), 'coverage_percent')
            && str_contains($request->body(), 'adsense_policy_review_matrix')
            && str_contains($request->body(), 'policy_references')
            && str_contains($request->body(), 'Publisher identity, honesty and transparency'));
    }

    public function test_gemini_uses_query_api_key_without_a_bearer_header(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'gemini',
            'maxguard.ai.api_key' => 'gemini-test-key',
            'maxguard.ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'maxguard.ai.gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'maxguard.ai.model' => 'gemini-test-model',
            'maxguard.ai.output_language' => 'Vietnamese',
        ]);
        Http::fake([
            '*' => Http::response([
                'responseId' => 'gemini-review-1',
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'risk_level' => 'healthy',
                    'headline' => 'Không có vấn đề nghiêm trọng',
                    'summary' => 'Dữ liệu hiện tại chưa ghi nhận finding đang mở.',
                    'key_issues' => [],
                    'priorities' => [],
                    'limitations' => [],
                ], JSON_UNESCAPED_UNICODE)]]]]],
            ]),
        ]);

        $owner = User::factory()->create();
        $website = Website::query()->create([
            'user_id' => $owner->id,
            'name' => 'Gemini Publisher',
            'slug' => 'gemini-publisher-example',
            'domain' => 'gemini-publisher.example',
            'start_url' => 'https://gemini-publisher.example/',
            'status' => 'healthy',
            'overall_score' => 100,
            'last_scanned_at' => now(),
        ]);
        $scan = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_COMPLETED,
            'progress' => 100,
            'pages_discovered' => 1,
            'pages_scanned' => 1,
            'score' => 100,
            'finished_at' => now(),
        ]);

        app(\App\Services\WebsiteAiReviewer::class)->reviewAndStore($scan);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['key'] ?? null) === 'gemini-test-key'
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_anthropic_website_review_uses_messages_api(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'anthropic',
            'maxguard.ai.api_key' => 'anthropic-test-key',
            'maxguard.ai.base_url' => 'https://api.anthropic.test/v1',
            'maxguard.ai.model' => 'claude-test-model',
            'maxguard.ai.output_language' => 'Vietnamese',
        ]);
        Http::fake([
            'https://api.anthropic.test/v1/messages' => Http::response([
                'id' => 'msg_review_1',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'risk_level' => 'healthy',
                    'headline' => 'Chưa phát hiện rủi ro lớn',
                    'summary' => 'Dữ liệu quét hiện tại chưa có tín hiệu đáng kể.',
                    'content_overview' => 'Chưa phát hiện tín hiệu nội dung đáng kể.',
                    'transparency_overview' => 'Chưa đủ dữ liệu để kết luận.',
                    'adsense_requirements_overview' => 'Cần tiếp tục theo dõi.',
                    'policy_overview' => 'Không có finding chính sách đang mở.',
                    'policy_references' => [],
                    'recommendations' => [],
                    'limitations' => [],
                ], JSON_UNESCAPED_UNICODE)]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ]),
        ]);

        $owner = User::factory()->create();
        $website = Website::query()->create([
            'user_id' => $owner->id,
            'name' => 'Claude Publisher',
            'slug' => 'claude-publisher-example',
            'domain' => 'claude-publisher.example',
            'start_url' => 'https://claude-publisher.example/',
            'status' => 'healthy',
            'overall_score' => 100,
        ]);
        $scan = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_COMPLETED,
            'progress' => 100,
            'pages_discovered' => 1,
            'pages_scanned' => 1,
            'score' => 100,
            'finished_at' => now(),
        ]);

        app(\App\Services\WebsiteAiReviewer::class)->reviewAndStore($scan);

        $this->assertSame('anthropic', $scan->fresh()->ai_assessment['provider']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.anthropic.test/v1/messages'
            && $request->hasHeader('x-api-key', 'anthropic-test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && data_get($request->data(), 'output_config.format.type') === 'json_schema');
    }
}
