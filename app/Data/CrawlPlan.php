<?php

namespace App\Data;

final class CrawlPlan
{
    /** @var list<string> */
    public array $urls = [];

    /** @var array<string, true> */
    private array $knownUrls = [];

    /** @var array<string, int> */
    public array $sourceCounts = [];

    public int $sitemapFiles = 0;
    public int $sitemapErrors = 0;
    public int $blockedByRobots = 0;
    public int $failedRequests = 0;
    public int $nonHtmlResponses = 0;
    public bool $truncated = false;
    public bool $sampled = false;
    public int $availableUrls = 0;
    public int $siteUrlsDiscovered = 0;
    public string $selectionMode = 'all_urls';

    public function __construct(
        public int $limit,
        public int $configuredLimit,
    ) {
    }

    public function addUrl(string $url, string $source): bool
    {
        $hash = hash('sha256', $url);
        if (isset($this->knownUrls[$hash])) {
            return false;
        }

        if (count($this->urls) >= $this->limit) {
            if (! $this->sampled) {
                $this->truncated = true;
            }

            return false;
        }

        $this->knownUrls[$hash] = true;
        $this->urls[] = $url;
        $this->sourceCounts[$source] = ($this->sourceCounts[$source] ?? 0) + 1;

        return true;
    }

    public function configureSelection(
        string $mode,
        int $availableUrls,
        int $siteUrlsDiscovered,
        bool $sampled,
    ): void {
        $this->selectionMode = $mode;
        $this->availableUrls = max(0, $availableUrls);
        $this->siteUrlsDiscovered = max(0, $siteUrlsDiscovered);
        $this->sampled = $sampled;
    }

    public function usesFixedSitemapSample(): bool
    {
        return in_array($this->selectionMode, ['latest_posts', 'latest_sitemap_urls', 'parallel_batch'], true);
    }

    public function count(): int
    {
        return count($this->urls);
    }

    public function discoveryConfidence(): string
    {
        if ($this->sitemapFiles > 0) {
            return 'high';
        }

        return ($this->sourceCounts['internal_link'] ?? 0) > 0 ? 'medium' : 'low';
    }

    /** @return array<string, mixed> */
    public function metadata(int $scanned): array
    {
        $discovered = $this->count();

        return [
            'discovery_sources' => $this->sourceCounts,
            'sitemap_files_processed' => $this->sitemapFiles,
            'sitemap_errors' => $this->sitemapErrors,
            'blocked_by_robots' => $this->blockedByRobots,
            'failed_requests' => $this->failedRequests,
            'non_html_responses' => $this->nonHtmlResponses,
            'configured_page_limit' => $this->configuredLimit,
            'effective_safety_limit' => $this->limit,
            'discovery_truncated' => $this->truncated,
            'discovery_confidence' => $this->discoveryConfidence(),
            'coverage_percent' => $discovered > 0 ? round(($scanned / $discovered) * 100, 2) : 0,
            'is_sampled' => $this->sampled,
            'sampling_mode' => $this->selectionMode,
            'available_urls' => $this->availableUrls,
            'site_urls_discovered' => $this->siteUrlsDiscovered,
            'sampled_urls' => $discovered,
            'site_coverage_percent' => $this->availableUrls > 0
                ? round((min($scanned, $this->availableUrls) / $this->availableUrls) * 100, 2)
                : 0,
        ];
    }
}
