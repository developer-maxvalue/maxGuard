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
        $detector = new DuplicateContentDetector();
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
