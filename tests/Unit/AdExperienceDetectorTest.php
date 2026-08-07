<?php

namespace Tests\Unit;

use App\Data\PageDocument;
use App\Detectors\AdExperienceDetector;
use Tests\TestCase;

final class AdExperienceDetectorTest extends TestCase
{
    public function test_it_flags_ads_on_a_page_without_meaningful_content(): void
    {
        $results = (new AdExperienceDetector())->detect($this->page(25, 1));

        $this->assertCount(1, $results);
        $this->assertSame('ads.on-page-without-content', $results[0]->ruleKey);
        $this->assertSame('high', $results[0]->severity);
        $this->assertSame('empty_or_nearly_empty', $results[0]->signals['content_state']);
    }

    public function test_it_flags_ads_on_a_thin_content_page(): void
    {
        $results = (new AdExperienceDetector())->detect($this->page(210, 2));

        $this->assertCount(1, $results);
        $this->assertSame('ads.on-thin-content-page', $results[0]->ruleKey);
        $this->assertSame('thin', $results[0]->signals['content_state']);
    }

    public function test_it_does_not_flag_a_content_rich_page_with_one_ad(): void
    {
        $this->assertSame([], (new AdExperienceDetector())->detect($this->page(800, 1)));
    }

    private function page(int $wordCount, int $adCount): PageDocument
    {
        return new PageDocument(
            url: 'https://example.com/article',
            statusCode: 200,
            html: '<html><body></body></html>',
            text: str_repeat('word ', $wordCount),
            title: 'Article',
            canonicalUrl: null,
            language: 'en',
            wordCount: $wordCount,
            adCount: $adCount,
            h1Count: 1,
            links: [],
            images: [],
        );
    }
}
