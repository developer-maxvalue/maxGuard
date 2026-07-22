<?php

namespace App\Services;

use App\Data\PageDocument;
use App\Models\Website;
use Generator;
use Throwable;

final class WebsiteCrawler
{
    public function __construct(
        private SafeHttpClient $http,
        private PageInspector $inspector,
        private UrlNormalizer $urls,
    ) {
    }

    /** @return Generator<int, PageDocument> */
    public function crawl(Website $website): Generator
    {
        $maxPages = max(1, (int) config('maxguard.crawler.max_pages', 100));
        $queue = [$this->urls->normalize($website->start_url)];
        $queue = array_values(array_unique(array_merge($queue, $this->sitemapUrls($website->start_url, $maxPages))));
        $visited = [];
        $robots = $this->robots($website->start_url);
        $siteSignals = $this->siteSignals($website->start_url);
        $delay = (int) round(1_000_000 / max(0.1, (float) config('maxguard.crawler.requests_per_second', 1.5)));

        while ($queue !== [] && count($visited) < $maxPages) {
            $url = array_shift($queue);
            if (! is_string($url) || isset($visited[hash('sha256', $url)]) || ! $this->isCrawlable($website->start_url, $url)) {
                continue;
            }

            $visited[hash('sha256', $url)] = true;
            if ((bool) config('maxguard.crawler.respect_robots', true) && ! $robots->allows($url)) {
                continue;
            }

            try {
                $response = $this->http->get($url);
                $contentType = strtolower((string) ($response->headers['Content-Type'][0] ?? $response->headers['content-type'][0] ?? ''));
                if ($response->status >= 400 || ($contentType !== '' && ! str_contains($contentType, 'html'))) {
                    continue;
                }

                $page = $this->inspector->inspect($response);
                if ($page->isHomePage()) {
                    $page->meta = array_merge($page->meta, $siteSignals);
                }
                yield $page;

                foreach ($page->links as $candidate) {
                    $resolved = $this->urls->resolve($page->url, $candidate);
                    if ($resolved !== null && count($queue) < ($maxPages * 10) && $this->isCrawlable($website->start_url, $resolved)) {
                        $queue[] = $resolved;
                    }
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            usleep($delay);
        }
    }

    /** @return list<string> */
    private function sitemapUrls(string $startUrl, int $limit): array
    {
        $origin = $this->origin($startUrl);
        try {
            $response = $this->http->get($origin.'/sitemap.xml', 'application/xml,text/xml,*/*');
            if ($response->status >= 400 || trim($response->body) === '') {
                return [];
            }

            $previous = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($response->body);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($xml === false) {
                return [];
            }

            $urls = [];
            foreach ($xml->xpath('//*[local-name()="loc"]') ?: [] as $location) {
                $url = trim((string) $location);
                if ($url !== '' && $this->isCrawlable($startUrl, $url)) {
                    $urls[] = $this->urls->normalize($url);
                }
                if (count($urls) >= $limit) {
                    break;
                }
            }

            return array_values(array_unique($urls));
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }

    private function robots(string $startUrl): RobotsPolicy
    {
        try {
            $response = $this->http->get($this->origin($startUrl).'/robots.txt', 'text/plain,*/*');

            return $response->status < 400 ? RobotsPolicy::fromText($response->body) : RobotsPolicy::fromText('');
        } catch (Throwable $exception) {
            report($exception);

            return RobotsPolicy::fromText('');
        }
    }

    /** @return array<string, mixed> */
    private function siteSignals(string $startUrl): array
    {
        try {
            $response = $this->http->get($this->origin($startUrl).'/ads.txt', 'text/plain,*/*');
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
    }

    private function isCrawlable(string $startUrl, string $url): bool
    {
        $first = preg_replace('/^www\./i', '', (string) parse_url($startUrl, PHP_URL_HOST));
        $second = preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST));
        if ($first === '' || strtolower($first) !== strtolower($second)) {
            return false;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return ! preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4|mp3|woff2?|ttf|css|js)$/i', $path);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
