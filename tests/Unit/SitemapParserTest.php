<?php

namespace Tests\Unit;

use App\Services\SitemapParser;
use PHPUnit\Framework\TestCase;

final class SitemapParserTest extends TestCase
{
    public function test_it_distinguishes_a_sitemap_index_from_article_urls(): void
    {
        $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <sitemap><loc>https://example.com/post-sitemap1.xml</loc><lastmod>2026-07-20T10:00:00+00:00</lastmod></sitemap>
            <sitemap><loc>https://example.com/post-sitemap2.xml</loc><lastmod>2026-07-21T10:00:00+00:00</lastmod></sitemap>
        </sitemapindex>
        XML;

        $result = (new SitemapParser())->parse($xml);

        $this->assertSame('index', $result['type']);
        $this->assertSame([
            'https://example.com/post-sitemap1.xml',
            'https://example.com/post-sitemap2.xml',
        ], $result['locations']);
        $this->assertSame('2026-07-20T10:00:00+00:00', $result['entries'][0]['lastmod']);
        $this->assertSame('2026-07-21T10:00:00+00:00', $result['entries'][1]['lastmod']);
    }

    public function test_it_only_returns_page_locations_from_a_urlset(): void
    {
        $xml = <<<'XML'
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
            <url><loc>https://example.com/article-one/</loc><lastmod>2026-07-22</lastmod><image:image><image:loc>https://example.com/photo.jpg</image:loc></image:image></url>
            <url><loc>https://example.com/article-two/</loc></url>
        </urlset>
        XML;

        $result = (new SitemapParser())->parse($xml);

        $this->assertSame('urlset', $result['type']);
        $this->assertSame([
            'https://example.com/article-one/',
            'https://example.com/article-two/',
        ], $result['locations']);
        $this->assertSame([
            ['loc' => 'https://example.com/article-one/', 'lastmod' => '2026-07-22'],
            ['loc' => 'https://example.com/article-two/', 'lastmod' => null],
        ], $result['entries']);
    }
}
