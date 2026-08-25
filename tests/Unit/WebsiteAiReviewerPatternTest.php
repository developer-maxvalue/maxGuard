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
                [
                    'author' => 'impression05',
                    'published_at' => '2026-08-24T08:00:00Z',
                    'content_structure' => ['signature' => 'shared-story-structure'],
                    'analysis_excerpt' => 'A repeated family conflict story with a surprise ending.',
                    'sensitive_topics' => ['domestic_violence'],
                    'presentation_styles' => ['sensational', 'clickbait'],
                ],
            ),
            $this->page(
                'Next part - A family entered a mental hospital but then something shocking happened',
                [
                    'author' => 'sonice07',
                    'published_at' => '2026-08-24T10:00:00Z',
                    'content_structure' => ['signature' => 'shared-story-structure'],
                    'analysis_excerpt' => 'Another repeated conflict story ending with an unexpected twist.',
                    'sensitive_topics' => ['mental_health'],
                    'presentation_styles' => ['sensational', 'clickbait'],
                ],
            ),
            $this->page(
                'About us',
                [
                    'authorship_claims' => ['human_written_claim', 'no_ai_claim', 'originality_claim'],
                    'publisher_claims' => [
                        ['type' => 'human_written_claim', 'quote' => '100% human-written', 'page_context' => 'about'],
                        ['type' => 'no_ai_claim', 'quote' => 'never use AI', 'page_context' => 'about'],
                        ['type' => 'originality_claim', 'quote' => '100% original', 'page_context' => 'about'],
                    ],
                    'institution_references' => ['harvard', 'yale'],
                    'trust_context_signals' => [
                        ['institution' => 'harvard', 'context_type' => 'trust_claim', 'element' => 'img', 'alt' => 'Harvard logo', 'heading' => 'Trusted by', 'surrounding_text' => 'Trusted by Harvard', 'link' => '', 'claim_phrase' => 'trusted by', 'page_context' => 'about'],
                        ['institution' => 'yale', 'context_type' => 'unverified_branding', 'element' => 'img', 'alt' => 'Yale logo', 'heading' => '', 'surrounding_text' => '', 'link' => '', 'claim_phrase' => '', 'page_context' => 'about'],
                    ],
                ],
                'about',
            ),
        ];

        $method = new ReflectionMethod(WebsiteAiReviewer::class, 'contentPatternEvidence');
        $result = $method->invoke(new WebsiteAiReviewer, $pages);

        $this->assertSame(2, $result['formulaic_or_cliffhanger_title_pages']);
        $this->assertSame(1, $result['next_part_title_pages']);
        $this->assertSame(2, $result['sensitive_sensational_title_pages']);
        $this->assertCount(2, $result['formulaic_title_example_urls']);
        $this->assertSame(2, $result['bot_like_author_pages']);
        $this->assertSame(2, $result['maximum_posts_on_one_published_date']);
        $this->assertSame(2, $result['most_common_structure_page_count']);
        $this->assertCount(2, $result['semantic_comparison_samples']);
        $this->assertSame(2, $result['sensitive_topic_with_risky_presentation_pages']);
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
            'key_issues' => array_map(
                fn (int $number): array => ['title' => "AI issue {$number}"],
                range(1, 6),
            ),
            'policy_references' => [],
        ], [
            'whole_site_page_profile' => ['content_pattern_evidence' => $assessmentEvidence],
        ]);

        $this->assertSame('high', $assessment['risk_level']);
        $this->assertContains('Mô hình nội dung sản xuất hàng loạt cần được xem xét', array_column($assessment['key_issues'], 'title'));
        $this->assertContains('Mâu thuẫn tín hiệu giữa lời cam kết và thực tế nội dung', array_column($assessment['key_issues'], 'title'));
        $this->assertContains('Potential misleading trust signal – manual verification required', array_column($assessment['key_issues'], 'title'));
        $this->assertStringContainsString('scaled/low-value content', $assessment['content_overview']);
        $this->assertStringContainsString('tuyên bố tuyệt đối', $assessment['transparency_overview']);
        $this->assertStringContainsString('mô hình nội dung sản xuất hàng loạt', mb_strtolower($assessment['policy_overview']));
        $scaledIssue = collect($assessment['key_issues'])->firstWhere('title', 'Mô hình nội dung sản xuất hàng loạt cần được xem xét');
        $this->assertSame('Content quality', $scaledIssue['category']);
        $this->assertCount(2, $scaledIssue['example_urls']);
        $this->assertSame('https://support.google.com/adsense/answer/81904?hl=vi', $scaledIssue['policy_url']);
        $claimIssue = collect($assessment['key_issues'])->firstWhere('title', 'Mâu thuẫn tín hiệu giữa lời cam kết và thực tế nội dung');
        $this->assertSame('high', $claimIssue['severity']);
        $this->assertStringContainsString('12 tiêu đề', $claimIssue['observation']);
        $this->assertStringContainsString('review thủ công', $claimIssue['why_it_matters']);
        $this->assertCount(2, $claimIssue['example_urls']);
        $this->assertSame('questionable', collect($assessment['claim_assessments'])->firstWhere('claim_type', 'no_ai_claim')['status']);
    }

    public function test_it_groups_ai_issues_by_root_cause(): void
    {
        $issue = [
            'root_cause' => 'Scaled / low-value publishing pattern',
            'severity' => 'review',
            'category' => 'Content quality',
            'observation' => 'Observed pattern.',
            'risk_signal' => 'Risk signal.',
            'why_it_matters' => 'Interpretation.',
            'evidence' => 'Evidence.',
            'supporting_evidence' => ['Repeated titles'],
            'policy_area' => 'Content quality',
            'confidence' => 70,
            'manual_verification' => 'Manual review.',
            'alternative_explanation' => 'Fixed editorial format.',
            'alternative_assessment' => 'Not enough to dismiss the main hypothesis.',
            'example_urls' => [],
            'policy_url' => 'https://support.google.com/adsense/answer/81904?hl=vi',
        ];
        $payload = [
            'risk_level' => 'review',
            'key_issues' => [
                array_merge($issue, ['title' => 'Repetitive titles']),
                array_merge($issue, ['title' => 'Generic authors']),
            ],
        ];

        $method = new ReflectionMethod(WebsiteAiReviewer::class, 'normalize');
        $result = $method->invoke(new WebsiteAiReviewer, $payload, []);

        $this->assertCount(1, $result['key_issues']);
        $this->assertSame('Scaled / low-value publishing pattern', $result['key_issues'][0]['root_cause']);
        $this->assertSame('Fixed editorial format.', $result['key_issues'][0]['alternative_explanation']);
    }

    public function test_it_does_not_infer_scaled_publishing_from_one_signal(): void
    {
        $method = new ReflectionMethod(WebsiteAiReviewer::class, 'applyPatternFindings');
        $assessment = $method->invoke(new WebsiteAiReviewer, [
            'risk_level' => 'healthy',
            'key_issues' => [],
            'policy_references' => [],
        ], [
            'whole_site_page_profile' => [
                'content_pattern_evidence' => [
                    'formulaic_or_cliffhanger_title_pages' => 50,
                    'formulaic_title_ratio_percent' => 90,
                    'next_part_title_pages' => 0,
                    'bot_like_author_pages' => 0,
                    'maximum_posts_on_one_published_date' => 1,
                    'most_common_structure_page_count' => 1,
                    'most_common_structure_ratio_percent' => 2,
                ],
            ],
        ]);

        $this->assertNotContains('Mô hình nội dung sản xuất hàng loạt cần được xem xét', array_column($assessment['key_issues'], 'title'));
    }

    /** @param array<string, mixed> $meta */
    private function page(string $title, array $meta, ?string $essentialPageType = null): Page
    {
        return (new Page)->forceFill([
            'url' => 'https://example.test/'.substr(hash('sha256', $title), 0, 12),
            'title' => $title,
            'meta' => $meta,
            'essential_page_type' => $essentialPageType,
        ]);
    }
}
