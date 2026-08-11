<?php

namespace Tests\Unit;

use App\Data\PageDocument;
use App\Detectors\DuplicateContentDetector;
use Tests\TestCase;

final class DuplicateContentDetectorTest extends TestCase
{
    public function test_sketch_index_finds_a_near_duplicate_without_comparing_every_page_pair(): void
    {
        config()->set('maxguard.thresholds.duplicate_similarity', 0.80);
        $detector = new DuplicateContentDetector;
        $text = implode(' ', array_map(fn (int $index): string => 'original-word-'.$index, range(1, 220)));

        $first = $this->page('https://example.com/first', $text);
        $second = $this->page('https://example.com/second', str_replace('original-word-110', 'changed-word-110', $text));

        $this->assertSame([], $detector->detect($first));
        $results = $detector->detect($second);

        $this->assertCount(1, $results);
        $this->assertSame('duplicate.internal-near-match', $results[0]->ruleKey);
        $this->assertSame('https://example.com/first', $results[0]->signals['matched_url']);
        $this->assertNotEmpty($results[0]->signals['matching_phrases']);
    }

    public function test_tag_and_category_listings_are_not_indexed_as_duplicate_articles(): void
    {
        config()->set('maxguard.thresholds.duplicate_similarity', 0.80);
        $detector = new DuplicateContentDetector;
        $text = implode(' ', array_map(fn (int $index): string => 'shared-word-'.$index, range(1, 220)));

        $this->assertSame([], $detector->detect($this->page(
            'https://www.ninhtito.com/blog/tag/khu+du+l%E1%BB%8Bch',
            $text,
        )));
        $this->assertSame([], $detector->detect($this->page(
            'https://www.ninhtito.com/blog/category/du-lich',
            $text,
        )));

        // The listings were not remembered, so a real article with the same
        // template/excerpt text must not match either listing.
        $this->assertSame([], $detector->detect($this->page(
            'https://www.ninhtito.com/blog/bai-viet-that',
            $text,
        )));
    }

    private function page(string $url, string $text): PageDocument
    {
        return new PageDocument(
            url: $url,
            statusCode: 200,
            html: '<html><body>'.$text.'</body></html>',
            text: $text,
            title: 'Article',
            canonicalUrl: null,
            language: 'en',
            wordCount: 220,
            adCount: 0,
            h1Count: 1,
            links: [],
            images: [],
        );
    }
}
