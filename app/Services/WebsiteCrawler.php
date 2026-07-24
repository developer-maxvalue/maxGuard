<?php

namespace App\Services;

use App\Data\CrawlPlan;
use App\Data\PageDocument;
use App\Models\Website;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SplQueue;
use Throwable;

final class WebsiteCrawler
{
    public function __construct(
        private SafeHttpClient $http,
        private PageInspector $inspector,
        private UrlNormalizer $urls,
        private SitemapParser $sitemaps,
    ) {
    }

    public function discover(Website $website, ?int $scanLimit = null): CrawlPlan
    {
        $configuredLimit = $scanLimit !== null
            ? max(1, $scanLimit)
            : max(0, (int) config('maxguard.crawler.max_pages', 0));
        $safetyLimit = (int) config('maxguard.crawler.max_discovered_urls', 100_000);
        $safetyLimit = $safetyLimit > 0 ? $safetyLimit : PHP_INT_MAX;
        $effectiveLimit = $configuredLimit > 0 ? min($configuredLimit, $safetyLimit) : $safetyLimit;
        $plan = new CrawlPlan($effectiveLimit, $configuredLimit);
        $startUrl = $this->urls->normalize($website->start_url);

        $robots = $this->robots($startUrl);
        $seeds = [];
        foreach ($robots->sitemaps() as $sitemap) {
            $seeds[] = ['url' => $sitemap, 'required' => true];
        }

        $origin = $this->origin($startUrl);
        foreach (['/sitemap.xml', '/sitemap_index.xml', '/wp-sitemap.xml'] as $path) {
            $seeds[] = ['url' => $origin.$path, 'required' => false];
        }

        $this->discoverSitemaps($startUrl, $seeds, $plan);
        if ($plan->count() === 0) {
            if ($configuredLimit > 0) {
                $plan->configureSelection('crawl_sample', 0, 0, true);
            }
            $plan->addUrl($startUrl, 'start_url');
        }

        return $plan;
    }

    /** @return Generator<int, PageDocument> */
    public function crawl(Website $website, ?CrawlPlan $plan = null): Generator
    {
        $plan ??= $this->discover($website);
        $startUrl = $this->urls->normalize($website->start_url);
        $queue = new SplQueue();
        $queued = [];
        foreach ($plan->urls as $url) {
            $queue->enqueue($url);
            $queued[hash('sha256', $url)] = true;
        }

        $visited = [];
        $visitedFinalUrls = [];
        $robots = $this->robots($startUrl);
        $siteSignals = $this->siteSignals($startUrl);
        $followLinks = (bool) config('maxguard.crawler.follow_internal_links', true)
            && ! $plan->usesFixedSitemapSample();

        while (! $queue->isEmpty()) {
            $url = $queue->dequeue();
            $hash = hash('sha256', $url);
            if (isset($visited[$hash]) || ! $this->isCrawlable($startUrl, $url)) {
                continue;
            }

            $visited[$hash] = true;
            if ((bool) config('maxguard.crawler.respect_robots', true) && ! $robots->allows($url)) {
                $plan->blockedByRobots++;
                continue;
            }

            try {
                $response = $this->http->get($url);
                if (! $this->isCrawlable($startUrl, $response->url)) {
                    $plan->failedRequests++;
                    continue;
                }

                $finalHash = hash('sha256', $this->urls->normalize($response->url));
                if (isset($visitedFinalUrls[$finalHash])) {
                    continue;
                }
                $visitedFinalUrls[$finalHash] = true;

                $contentType = strtolower($this->headerValue($response->headers, 'content-type'));
                if ($response->status >= 400) {
                    $plan->failedRequests++;
                    continue;
                }
                if ($contentType !== '' && ! str_contains($contentType, 'html')) {
                    $plan->nonHtmlResponses++;
                    continue;
                }

                $page = $this->inspector->inspect($response);
                $page->meta['crawl_source_url'] = $url;
                if ($page->isHomePage()) {
                    $page->meta = array_merge($page->meta, $siteSignals);
                }

                if ($followLinks) {
                    foreach ($page->links as $candidate) {
                        $resolved = $this->urls->resolve($page->url, $candidate);
                        if ($resolved === null || ! $this->isCrawlable($startUrl, $resolved)) {
                            continue;
                        }

                        $resolved = $this->urls->normalize($resolved);
                        if ($plan->addUrl($resolved, 'internal_link')) {
                            $resolvedHash = hash('sha256', $resolved);
                            if (! isset($queued[$resolvedHash])) {
                                $queue->enqueue($resolved);
                                $queued[$resolvedHash] = true;
                            }
                        }
                    }
                }

                yield $page;
            } catch (Throwable $exception) {
                $plan->failedRequests++;
                $plan->recordUrlError($url, $exception->getMessage());
                // A remote URL redirect/timeout is an expected target failure,
                // not an application exception requiring a full stack trace.
                Log::warning('Crawler could not fetch URL.', [
                    'url' => $url,
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
            }
        }
    }

    /**
     * @param list<array{url: string, required: bool}> $seeds
     */
    private function discoverSitemaps(string $startUrl, array $seeds, CrawlPlan $plan): void
    {
        $queue = new SplQueue();
        foreach ($seeds as $seed) {
            $queue->enqueue($seed);
        }

        $seenRequests = [];
        $seenFinalUrls = [];
        $seenCandidates = [];
        $candidates = [];
        $sequence = 0;
        $maxSitemaps = max(1, (int) config('maxguard.crawler.max_sitemaps', 1000));
        $candidateLimit = max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000));
        $processed = 0;

        while (! $queue->isEmpty() && $processed < $maxSitemaps) {
            $seed = $queue->dequeue();
            $sitemapUrl = $this->urls->normalize($seed['url']);
            $requestHash = hash('sha256', $sitemapUrl);
            if (isset($seenRequests[$requestHash])) {
                continue;
            }
            if (! $this->sameSiteHost($startUrl, $sitemapUrl)) {
                if ($seed['required']) {
                    $plan->sitemapErrors++;
                }
                continue;
            }

            $seenRequests[$requestHash] = true;
            $processed++;

            try {
                $response = $this->http->get($sitemapUrl, 'application/xml,text/xml,application/gzip,*/*');
                if ($response->status >= 400 || trim($response->body) === '') {
                    if ($seed['required']) {
                        $plan->sitemapErrors++;
                    }
                    continue;
                }
                if (! $this->sameSiteHost($startUrl, $response->url)) {
                    if ($seed['required']) {
                        $plan->sitemapErrors++;
                    }
                    continue;
                }

                $finalHash = hash('sha256', $this->urls->normalize($response->url));
                if (isset($seenFinalUrls[$finalHash])) {
                    continue;
                }
                $seenFinalUrls[$finalHash] = true;

                $parsed = $this->sitemaps->parse($response->body);
                if ($parsed['type'] === 'unknown') {
                    if ($seed['required']) {
                        $plan->sitemapErrors++;
                    }
                    continue;
                }

                $plan->sitemapFiles++;
                if ($parsed['type'] === 'index') {
                    $entries = $parsed['entries'];
                    $resolvedEntries = [];
                    foreach ($entries as $entry) {
                        $resolved = $this->urls->resolve($response->url, $entry['loc']);
                        if ($resolved !== null && $this->sameSiteHost($startUrl, $resolved)) {
                            $resolvedEntries[] = ['url' => $resolved, 'lastmod' => $entry['lastmod']];
                        }
                    }
                    $postEntries = array_values(array_filter(
                        $resolvedEntries,
                        fn (array $entry): bool => $this->isPostSitemap($entry['url'])
                    ));
                    if ($plan->configuredLimit > 0 && $postEntries !== []) {
                        $resolvedEntries = $postEntries;
                    }
                    usort($resolvedEntries, fn (array $first, array $second): int => $this->lastModifiedTimestamp($second['lastmod']) <=> $this->lastModifiedTimestamp($first['lastmod']));
                    foreach ($resolvedEntries as $entry) {
                        $queue->enqueue(['url' => $entry['url'], 'required' => true]);
                    }
                    continue;
                }

                $postSitemap = $this->isPostSitemap($response->url);
                foreach ($parsed['entries'] as $entry) {
                    $resolved = $this->urls->resolve($response->url, $entry['loc']);
                    if ($resolved !== null && $this->isCrawlable($startUrl, $resolved)) {
                        $resolved = $this->urls->normalize($resolved);
                        $candidateHash = hash('sha256', $resolved);
                        if (isset($seenCandidates[$candidateHash])) {
                            continue;
                        }
                        if (count($candidates) >= $candidateLimit) {
                            $plan->truncated = true;

                            break 2;
                        }

                        $seenCandidates[$candidateHash] = true;
                        $candidates[] = [
                            'url' => $resolved,
                            'lastmod' => $this->lastModifiedTimestamp($entry['lastmod']),
                            'post' => $postSitemap,
                            'sequence' => $sequence++,
                        ];
                    }
                }
            } catch (Throwable $exception) {
                if ($seed['required']) {
                    $plan->sitemapErrors++;
                }
                report($exception);
            }
        }

        if (! $queue->isEmpty()) {
            $plan->truncated = true;
        }

        $this->selectUrls($startUrl, $candidates, $plan);
    }

    /**
     * @param list<array{url: string, lastmod: int, post: bool, sequence: int}> $candidates
     */
    private function selectUrls(string $startUrl, array $candidates, CrawlPlan $plan): void
    {
        $postCandidates = array_values(array_filter($candidates, fn (array $candidate): bool => $candidate['post']));
        $limited = $plan->configuredLimit > 0;

        if (! $limited) {
            $plan->configureSelection('all_urls', count($candidates), count($candidates), false);
            $plan->addUrl($startUrl, 'start_url');
            foreach ($candidates as $candidate) {
                $plan->addUrl($candidate['url'], 'sitemap');
            }

            return;
        }

        $latestPosts = $postCandidates !== [];
        $pool = $latestPosts ? $postCandidates : $candidates;
        usort($pool, static fn (array $first, array $second): int => ($second['lastmod'] <=> $first['lastmod'])
            ?: ($first['sequence'] <=> $second['sequence']));

        $plan->configureSelection(
            $latestPosts ? 'latest_posts' : 'latest_sitemap_urls',
            count($pool),
            count($candidates),
            count($pool) > $plan->limit,
        );

        foreach (array_slice($pool, 0, $plan->limit) as $candidate) {
            $plan->addUrl($candidate['url'], 'sitemap');
        }
    }

    private function lastModifiedTimestamp(?string $lastModified): int
    {
        if ($lastModified === null || $lastModified === '') {
            return 0;
        }

        $timestamp = strtotime($lastModified);

        return $timestamp === false ? 0 : $timestamp;
    }

    private function isPostSitemap(string $url): bool
    {
        $filename = strtolower(basename((string) parse_url($url, PHP_URL_PATH)));

        return (bool) preg_match(
            '/^(?:post|posts)-sitemap\d*\.xml(?:\.gz)?$|^wp-sitemap-posts-post-\d+\.xml(?:\.gz)?$/i',
            $filename,
        );
    }

    private function robots(string $startUrl): RobotsPolicy
    {
        $origin = $this->origin($startUrl);

        return Cache::remember(
            'maxguard:crawl:robots:'.hash('sha256', $origin),
            now()->addMinutes(5),
            function () use ($origin): RobotsPolicy {
                try {
                    $response = $this->http->get($origin.'/robots.txt', 'text/plain,*/*');

                    return $response->status < 400 ? RobotsPolicy::fromText($response->body) : RobotsPolicy::fromText('');
                } catch (Throwable $exception) {
                    report($exception);

                    return RobotsPolicy::fromText('');
                }
            },
        );
    }

    /** @return array<string, mixed> */
    private function siteSignals(string $startUrl): array
    {
        $origin = $this->origin($startUrl);

        return Cache::remember(
            'maxguard:crawl:site-signals:'.hash('sha256', $origin),
            now()->addMinutes(5),
            function () use ($origin): array {
                try {
                    $response = $this->http->get($origin.'/ads.txt', 'text/plain,*/*');
                    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $response->body) ?: []), fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));

                    return [
                        'ads_txt_status' => $response->status,
                        'ads_txt_present' => $response->status < 400 && $lines !== [],
                        'ads_txt_lines' => count($lines),
                        'ads_txt_has_google' => collect($lines)->contains(fn (string $line): bool => str_starts_with(strtolower($line), 'google.com,')),
                    ];
                } catch (Throwable $exception) {
                    report($exception);

                    return ['ads_txt_status' => null, 'ads_txt_present' => false, 'ads_txt_lines' => 0, 'ads_txt_has_google' => false];
                }
            },
        );
    }

    private function isCrawlable(string $startUrl, string $url): bool
    {
        if (! $this->sameSiteHost($startUrl, $url)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return ! preg_match('/\.(?:jpg|jpeg|png|gif|webp|avif|svg|pdf|zip|gz|xml|mp4|webm|mp3|wav|woff2?|ttf|css|js|json)$/i', $path);
    }

    private function sameSiteHost(string $first, string $second): bool
    {
        $firstHost = preg_replace('/^www\./i', '', (string) parse_url($first, PHP_URL_HOST));
        $secondHost = preg_replace('/^www\./i', '', (string) parse_url($second, PHP_URL_HOST));

        return $firstHost !== '' && strtolower($firstHost) === strtolower($secondHost);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** @param array<string, string|array<string>> $headers */
    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $header => $values) {
            if (strtolower($header) !== strtolower($name)) {
                continue;
            }

            return is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return '';
    }

}
