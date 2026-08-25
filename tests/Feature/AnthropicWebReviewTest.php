<?php

namespace Tests\Feature;

use App\Jobs\GenerateWebsiteAiAssessment;
use App\Jobs\RunAnthropicWebReview;
use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use App\Services\AnthropicWebReviewer;
use App\Services\ScanDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AnthropicWebReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_anthropic_web_review_persists_verified_example_urls_and_citations(): void
    {
        $this->configureAnthropic();
        Http::fake([
            'https://api.anthropic.test/v1/messages' => Http::response([
                'id' => 'msg_web_review_1',
                'content' => [
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_home',
                        'name' => 'web_fetch',
                        'input' => ['url' => 'https://publisher.example/'],
                    ],
                    [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_home',
                        'content' => [
                            'type' => 'web_fetch_result',
                            'url' => 'https://publisher.example/',
                            'content' => ['type' => 'document', 'title' => 'Publisher home'],
                        ],
                    ],
                    [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_article',
                        'content' => [
                            'type' => 'web_fetch_result',
                            'url' => 'https://publisher.example/repeated-story',
                            'content' => ['type' => 'document', 'title' => 'Repeated story'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => json_encode([
                            'risk_level' => 'high',
                            'headline' => 'Có tín hiệu nội dung xuất bản theo mẫu',
                            'summary' => 'Claude Web quan sát thấy cấu trúc nội dung lặp lại cần được xác minh.',
                            'issues' => [[
                                'title' => 'Mẫu nội dung lặp lại trên bài viết',
                                'category' => 'Content quality',
                                'severity' => 'high',
                                'confidence' => 84,
                                'observation' => 'Bài viết sử dụng cấu trúc và câu mở đầu lặp lại.',
                                'why_it_matters' => 'Đây là tín hiệu nội dung có giá trị biên tập thấp, cần kiểm tra thủ công.',
                                'evidence_quotes' => ['CHAPTER 2 — the same formula continues.'],
                                'example_urls' => [
                                    'https://publisher.example/repeated-story',
                                    'https://publisher.example/not-actually-fetched',
                                ],
                                'policy_url' => 'https://invalid.example/hallucinated-policy',
                                'citations' => [[
                                    'url' => 'https://publisher.example/repeated-story',
                                    'title' => 'Repeated story',
                                    'cited_text' => 'CHAPTER 2 — the same formula continues.',
                                ]],
                            ]],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 1200,
                    'output_tokens' => 300,
                    'server_tool_use' => ['web_fetch_requests' => 2],
                ],
            ]),
        ]);

        $owner = User::factory()->create();
        $website = $this->website($owner);
        $scan = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_COMPLETED,
            'progress' => 100,
            'use_ai' => true,
            'finished_at' => now(),
            'meta' => ['web_review_status' => 'failed'],
        ]);

        (new GenerateWebsiteAiAssessment($scan->id))->handle(
            app(\App\Services\AiConfiguration::class),
            app(\App\Services\WebsiteAiReviewer::class),
            app(AnthropicWebReviewer::class),
        );

        $finding = $scan->findings()->firstOrFail();
        $this->assertNull($finding->page_id);
        $this->assertSame('https://publisher.example/repeated-story', data_get($finding->signals, 'evidence_url'));
        $this->assertSame('anthropic_web', data_get($finding->signals, 'analysis_source'));
        $this->assertSame('https://support.google.com/adsense/answer/81904?hl=vi', data_get($finding->signals, 'policy_url'));
        $this->assertSame(['https://publisher.example/repeated-story'], data_get($finding->signals, 'example_urls'));
        $this->assertSame('completed', data_get($scan->fresh()->meta, 'web_review_status'));
        $this->assertSame('Có tín hiệu nội dung xuất bản theo mẫu', data_get($scan->fresh()->meta, 'web_review.headline'));
        $this->assertSame(2, data_get($scan->fresh()->meta, 'web_review_usage.server_tool_use.web_fetch_requests'));

        $assessment = $scan->fresh()->ai_assessment;
        $this->assertSame('anthropic_web', $assessment['assessment_source']);
        $this->assertSame('Mẫu nội dung lặp lại trên bài viết', data_get($assessment, 'key_issues.0.title'));
        $this->assertSame(['https://publisher.example/repeated-story'], data_get($assessment, 'key_issues.0.example_urls'));
        $this->assertSame(['CHAPTER 2 — the same formula continues.'], data_get($assessment, 'key_issues.0.evidence_quotes'));

        $this->actingAs($owner)
            ->get(route('sites.show', $website))
            ->assertOk()
            ->assertSee('Claude Web')
            ->assertSee('Claude Web · dữ liệu website realtime')
            ->assertSee('Mẫu nội dung lặp lại trên bài viết')
            ->assertSee('https://publisher.example/repeated-story', false);
        $this->actingAs($owner)
            ->get(route('findings.show', $finding))
            ->assertOk()
            ->assertSee('Nguồn Claude Web đã đọc')
            ->assertSee('CHAPTER 2 — the same formula continues.');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.anthropic.test/v1/messages'
                && $request->hasHeader('x-api-key', 'anthropic-test-key')
                && data_get($request->data(), 'tools.0.type') === 'web_search_20260318'
                && data_get($request->data(), 'tools.1.type') === 'web_fetch_20260318'
                && data_get($request->data(), 'tools.1.citations.enabled') === true
                && data_get($request->data(), 'thinking.type') === 'disabled'
                && ! array_key_exists('effort', $request->data())
                && data_get($request->data(), 'output_config.format.type') === 'json_schema'
                && in_array('conclusion', (array) data_get($request->data(), 'output_config.format.schema.required'), true)
                && str_contains((string) data_get($request->data(), 'messages.0.content'), 'Kiểm tra cho tôi website')
                && str_contains((string) data_get($request->data(), 'messages.0.content'), '1-2 URL')
                && str_contains((string) data_get($request->data(), 'system'), 'not like a forensic audit')
                && str_contains((string) data_get($request->data(), 'system'), '3-5 strongest distinct issues');
        });
        Http::assertSentCount(1);
    }

    public function test_anthropic_web_review_is_queued_beside_the_crawler(): void
    {
        Queue::fake();
        $this->configureAnthropic();
        $website = $this->website(User::factory()->create());

        $scan = app(ScanDispatcher::class)->dispatch($website, 'full', null, 25, true);

        Queue::assertPushed(RunAnthropicWebReview::class, fn (RunAnthropicWebReview $job): bool => $job->scanId === $scan->id
            && $job->queue === 'scans');
        $this->assertSame('queued', data_get($scan->fresh()->meta, 'web_review_status'));
    }

    public function test_manual_assessment_queues_realtime_review_for_an_existing_scan(): void
    {
        Queue::fake();
        $this->configureAnthropic();
        $owner = User::factory()->create();
        $website = $this->website($owner);
        $scan = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_COMPLETED,
            'progress' => 100,
            'use_ai' => true,
            'finished_at' => now(),
            'meta' => ['web_review_status' => 'failed'],
        ]);

        $this->actingAs($owner)
            ->post(route('sites.ai-assessment', $website))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(GenerateWebsiteAiAssessment::class, fn (GenerateWebsiteAiAssessment $job): bool => $job->scanId === $scan->id);
        $this->assertSame('queued', data_get($scan->fresh()->meta, 'ai_assessment_status'));
        $this->assertTrue((bool) data_get($scan->fresh()->meta, 'web_review_refresh_requested'));
    }

    private function configureAnthropic(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'gemini',
            'maxguard.ai.api_key' => 'gemini-test-key',
            'maxguard.ai.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'maxguard.ai.gemini_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'maxguard.ai.model' => 'gemini-2.5-flash',
            'maxguard.ai.output_language' => 'Vietnamese',
            'maxguard.review_ai.enabled' => true,
            'maxguard.review_ai.provider' => 'anthropic',
            'maxguard.review_ai.api_key' => 'anthropic-test-key',
            'maxguard.review_ai.base_url' => 'https://api.anthropic.test/v1',
            'maxguard.review_ai.model' => 'claude-sonnet-4-6',
            'maxguard.review_ai.connect_timeout_seconds' => 10,
            'maxguard.review_ai.timeout_seconds' => 300,
            'maxguard.review_ai.max_output_tokens' => 6000,
            'maxguard.web_review.enabled' => true,
            'maxguard.web_review.queue' => 'scans',
            'maxguard.web_review.min_confidence' => 60,
        ]);
    }

    private function website(User $owner): Website
    {
        return Website::query()->create([
            'user_id' => $owner->id,
            'name' => 'Publisher',
            'slug' => 'publisher-example',
            'domain' => 'publisher.example',
            'start_url' => 'https://publisher.example/',
            'status' => 'pending',
        ]);
    }
}
