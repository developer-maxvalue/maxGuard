<?php

namespace Tests\Unit;

use App\Models\Page;
use App\Services\WebsiteAiReviewer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WebsiteAiReviewerPatternTest extends TestCase
{
    public function test_it_aggregates_scaled_content_and_transparency_evidence(): void
    {
        $pages = [
            $this->page(
                'A woman suffered domestic abuse... BUT THEN an unexpected twist happened',
                ['author' => 'impression05', 'published_at' => '2026-08-24T08:00:00Z'],
            ),
            $this->page(
                'Next part - A family entered a mental hospital but then something shocking happened',
                ['author' => 'sonice07', 'published_at' => '2026-08-24T10:00:00Z'],
            ),
            $this->page(
                'About us',
                [
                    'authorship_claims' => ['human_written_claim', 'no_ai_claim', 'originality_claim'],
                    'institution_references' => ['harvard', 'yale'],
                ],
                'about',
            ),
        ];

        $method = new ReflectionMethod(WebsiteAiReviewer::class, 'contentPatternEvidence');
        $result = $method->invoke(new WebsiteAiReviewer, $pages);

        $this->assertSame(2, $result['formulaic_or_cliffhanger_title_pages']);
        $this->assertSame(1, $result['next_part_title_pages']);
        $this->assertSame(2, $result['sensitive_sensational_title_pages']);
        $this->assertSame(2, $result['bot_like_author_pages']);
        $this->assertSame(2, $result['maximum_posts_on_one_published_date']);
        $this->assertSame(1, $result['strong_authorship_or_originality_claims']['no_ai_claim']);
        $this->assertSame(1, $result['institution_references_on_transparency_pages']['harvard']);

        $assessmentEvidence = array_merge($result, [
            'formulaic_or_cliffhanger_title_pages' => 12,
            'formulaic_title_ratio_percent' => 75.0,
            'next_part_title_pages' => 6,
            'bot_like_author_pages' => 8,
            'maximum_posts_on_one_published_date' => 9,
        ]);
        $assessmentMethod = new ReflectionMethod(WebsiteAiReviewer::class, 'applyPatternFindings');
        $assessment = $assessmentMethod->invoke(new WebsiteAiReviewer, [
            'risk_level' => 'healthy',
            'content_overview' => 'Tổng quan ban đầu.',
            'key_issues' => [],
            'priorities' => [],
            'policy_references' => [],
        ], [
            'whole_site_page_profile' => ['content_pattern_evidence' => $assessmentEvidence],
        ]);

        $this->assertSame('high', $assessment['risk_level']);
        $this->assertContains('Mô hình nội dung sản xuất hàng loạt cần được xem xét', array_column($assessment['key_issues'], 'title'));
        $this->assertContains('Tuyên bố tuyệt đối về tác giả và tính nguyên bản cần được xác minh', array_column($assessment['key_issues'], 'title'));
        $this->assertContains('Tín hiệu liên hệ với tổ chức cần được xác minh', array_column($assessment['key_issues'], 'title'));
        $this->assertStringContainsString('scaled/low-value content', $assessment['content_overview']);
        $this->assertStringContainsString('tuyên bố tuyệt đối', $assessment['transparency_overview']);
        $this->assertStringContainsString('mô hình nội dung sản xuất hàng loạt', mb_strtolower($assessment['policy_overview']));
        $this->assertNotEmpty($assessment['priorities']);
    }

    /** @param array<string, mixed> $meta */
    private function page(string $title, array $meta, ?string $essentialPageType = null): Page
    {
        return (new Page)->forceFill([
            'title' => $title,
            'meta' => $meta,
            'essential_page_type' => $essentialPageType,
        ]);
    }
}
