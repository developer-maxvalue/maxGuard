<?php

namespace Tests\Feature;

use App\Data\CrawlResponse;
use App\Models\Website;
use App\Services\PageInspector;
use App\Services\SafeHttpClient;
use App\Services\SitemapParser;
use App\Services\UrlNormalizer;
use App\Services\WebsiteCrawler;
use Tests\TestCase;

final class WebsiteCrawlerTest extends TestCase
{
    public function test_it_recursively_discovers_and_crawls_wordpress_sitemap_articles(): void
    {
        config()->set('maxguard.crawler.requests_per_second', 100_000);
        config()->set('maxguard.crawler.max_pages', 0);
        config()->set('maxguard.crawler.max_discovered_urls', 100);
        config()->set('maxguard.crawler.follow_internal_links', false);

        $responses = [
            'https://example.com/robots.txt' => new CrawlResponse('https://example.com/robots.txt', 200, "Sitemap: https://example.com/sitemap.xml\n"),
            'https://example.com/sitemap.xml' => new CrawlResponse('https://example.com/sitemap.xml', 200, <<<'XML'
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <sitemap><loc>https://example.com/post-sitemap.xml</loc></sitemap>
            </sitemapindex>
            XML),
            'https://example.com/post-sitemap.xml' => new CrawlResponse('https://example.com/post-sitemap.xml', 200, <<<'XML'
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/article-one/</loc></url>
                <url><loc>https://example.com/article-two/</loc></url>
            </urlset>
            XML),
            'https://example.com/ads.txt' => new CrawlResponse('https://example.com/ads.txt', 404, ''),
            'https://example.com/' => $this->html('https://example.com/', 'Home'),
            'https://example.com/article-one' => $this->html('https://example.com/article-one', 'Article one'),
            'https://example.com/article-two' => $this->html('https://example.com/article-two', 'Article two'),
        ];

        $crawler = new WebsiteCrawler(
            new FakeSafeHttpClient($responses),
            new PageInspector(),
            new UrlNormalizer(),
            new SitemapParser(),
        );
        $website = new Website(['start_url' => 'https://example.com/']);

        $plan = $crawler->discover($website);
        $pages = iterator_to_array($crawler->crawl($website, $plan));

        $this->assertSame(3, $plan->count());
        $this->assertSame(2, $plan->sitemapFiles);
        $this->assertSame(2, $plan->sourceCounts['sitemap']);
        $this->assertCount(3, $pages);
        $this->assertSame(['Home', 'Article one', 'Article two'], array_map(fn ($page): string => $page->title, $pages));
    }

    public function test_per_scan_url_limit_selects_the_latest_posts_without_marking_coverage_partial(): void
    {
        config()->set('maxguard.crawler.requests_per_second', 100_000);
        config()->set('maxguard.crawler.max_discovered_urls', 100);

        $responses = [
            'https://example.com/robots.txt' => new CrawlResponse('https://example.com/robots.txt', 200, "Sitemap: https://example.com/sitemap.xml\n"),
            'https://example.com/sitemap.xml' => new CrawlResponse('https://example.com/sitemap.xml', 200, <<<'XML'
            <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <sitemap><loc>https://example.com/page-sitemap.xml</loc><lastmod>2026-07-25</lastmod></sitemap>
                <sitemap><loc>https://example.com/post-sitemap.xml</loc><lastmod>2026-07-22</lastmod></sitemap>
            </sitemapindex>
            XML),
            'https://example.com/page-sitemap.xml' => new CrawlResponse('https://example.com/page-sitemap.xml', 200, <<<'XML'
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/privacy/</loc><lastmod>2026-07-25</lastmod></url>
            </urlset>
            XML),
            'https://example.com/post-sitemap.xml' => new CrawlResponse('https://example.com/post-sitemap.xml', 200, <<<'XML'
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/older/</loc><lastmod>2026-07-01</lastmod></url>
                <url><loc>https://example.com/newest/</loc><lastmod>2026-07-22</lastmod></url>
                <url><loc>https://example.com/middle/</loc><lastmod>2026-07-15</lastmod></url>
            </urlset>
            XML),
        ];

        $crawler = new WebsiteCrawler(
            new FakeSafeHttpClient($responses),
            new PageInspector(),
            new UrlNormalizer(),
            new SitemapParser(),
        );

        $plan = $crawler->discover(new Website(['start_url' => 'https://example.com/']), 2);

        $this->assertSame(2, $plan->count());
        $this->assertSame(2, $plan->configuredLimit);
        $this->assertSame([
            'https://example.com/newest',
            'https://example.com/middle',
        ], $plan->urls);
        $this->assertTrue($plan->sampled);
        $this->assertFalse($plan->truncated);
        $this->assertSame(2, $plan->sitemapFiles);
        $this->assertSame('latest_posts', $plan->selectionMode);
        $this->assertSame(3, $plan->availableUrls);
        $this->assertSame(3, $plan->siteUrlsDiscovered);
    }

    private function html(string $url, string $title): CrawlResponse
    {
        return new CrawlResponse($url, 200, "<html><head><title>{$title}</title></head><body><h1>{$title}</h1><p>Article body text.</p></body></html>", [
            'Content-Type' => ['text/html; charset=UTF-8'],
        ]);
    }
}

final class FakeSafeHttpClient extends SafeHttpClient
{
    /** @param array<string, CrawlResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function get(string $url, string $accept = 'text/html,application/xhtml+xml'): CrawlResponse
    {
        return $this->responses[$url] ?? new CrawlResponse($url, 404, '');
    }
}
