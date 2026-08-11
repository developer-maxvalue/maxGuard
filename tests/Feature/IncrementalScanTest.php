<?php

namespace Tests\Feature;

use App\Data\CrawlResponse;
use App\Models\Page;
use App\Models\Scan;
use App\Models\Website;
use App\Services\AiPolicyAnalyzer;
use App\Services\DetectorRegistry;
use App\Services\PageInspector;
use App\Services\RiskScoreCalculator;
use App\Services\SafeHttpClient;
use App\Services\ScanRunner;
use App\Services\SitemapParser;
use App\Services\UrlNormalizer;
use App\Services\WebsiteCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IncrementalScanTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_analysis_for_compatible_unchanged_content_unless_forced(): void
    {
        config()->set('maxguard.crawler.requests_per_second', 100_000);
        config()->set('maxguard.crawler.follow_internal_links', false);
        config()->set('maxguard.detectors', []);
        config()->set('maxguard.ai.enabled', false);
        config()->set('maxguard.browser_audit.enabled', false);
        config()->set('maxguard.external_copy.enabled', false);

        $html = '<html><head><title>Stable article</title></head><body><h1>Stable article</h1><p>The body has not changed.</p></body></html>';
        $responses = [
            'https://example.com/robots.txt' => new CrawlResponse('https://example.com/robots.txt', 200, "Sitemap: https://example.com/sitemap.xml\n"),
            'https://example.com/sitemap.xml' => new CrawlResponse('https://example.com/sitemap.xml', 200, <<<'XML'
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/</loc></url>
            </urlset>
            XML),
            'https://example.com/ads.txt' => new CrawlResponse('https://example.com/ads.txt', 404, ''),
            'https://example.com/' => new CrawlResponse('https://example.com/', 200, $html, [
                'Content-Type' => ['text/html; charset=UTF-8'],
            ]),
        ];
        $inspector = new PageInspector;
        $document = $inspector->inspect($responses['https://example.com/']);
        $website = Website::query()->create([
            'name' => 'Example',
            'slug' => 'example-com',
            'domain' => 'example.com',
            'start_url' => 'https://example.com/',
        ]);
        $marker = [
            'ruleset_version' => '1.1.1',
            'scan_type' => 'full',
            'ai_analyzed' => false,
            'ai_model' => null,
            'analyzed_at' => now()->subHour()->toIso8601String(),
        ];
        Page::query()->create([
            'website_id' => $website->id,
            'url' => $document->url,
            'url_hash' => hash('sha256', $document->url),
            'content_hash' => $document->contentHash(),
            'word_count' => $document->wordCount,
            'ad_count' => $document->adCount,
            'meta' => ['maxguard_analysis' => $marker],
        ]);

        $runner = $this->runner($responses, $inspector);
        $incremental = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_QUEUED,
            'ruleset_version' => '1.1.1',
            'use_ai' => false,
            'force_rescan' => false,
        ]);
        $runner->run($incremental);

        $incremental->refresh();
        $this->assertSame(Scan::STATUS_COMPLETED, $incremental->status);
        $this->assertSame(1, $incremental->pages_scanned);
        $this->assertSame(1, $incremental->pages_skipped_unchanged);
        $this->assertSame(0, data_get($incremental->meta, 'pages_analyzed'));
        $this->assertEquals($marker, data_get(Page::query()->firstOrFail()->meta, 'maxguard_analysis'));

        $forced = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_QUEUED,
            'ruleset_version' => '1.1.1',
            'use_ai' => false,
            'force_rescan' => true,
        ]);
        $runner->run($forced);

        $forced->refresh();
        $this->assertSame(Scan::STATUS_COMPLETED, $forced->status);
        $this->assertSame(1, $forced->pages_scanned);
        $this->assertSame(0, $forced->pages_skipped_unchanged);
        $this->assertSame(1, data_get($forced->meta, 'pages_analyzed'));
    }

    /** @param array<string, CrawlResponse> $responses */
    private function runner(array $responses, PageInspector $inspector): ScanRunner
    {
        $crawler = new WebsiteCrawler(
            new IncrementalFakeSafeHttpClient($responses),
            $inspector,
            new UrlNormalizer,
            new SitemapParser,
        );

        return new ScanRunner(
            $crawler,
            new DetectorRegistry,
            new AiPolicyAnalyzer,
            new RiskScoreCalculator,
        );
    }
}

final class IncrementalFakeSafeHttpClient extends SafeHttpClient
{
    /** @param array<string, CrawlResponse> $responses */
    public function __construct(private array $responses) {}

    public function get(string $url, string $accept = 'text/html,application/xhtml+xml'): CrawlResponse
    {
        return $this->responses[$url] ?? new CrawlResponse($url, 404, '');
    }
}
