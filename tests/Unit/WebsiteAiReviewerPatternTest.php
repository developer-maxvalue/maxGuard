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
