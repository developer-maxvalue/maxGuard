<?php

namespace App\Services;

use App\Data\DetectorResult;
use App\Data\PageDocument;
use App\Models\Finding;
use App\Models\Page;
use App\Models\Scan;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ScanRunner
{
    public function __construct(
        private WebsiteCrawler $crawler,
        private DetectorRegistry $detectors,
        private EvidenceStore $evidence,
        private RiskScoreCalculator $riskScore,
    ) {
    }

    public function run(Scan $scan): void
    {
        $scan->refresh();
        if ($scan->status === Scan::STATUS_COMPLETED) {
            return;
        }

        $website = $scan->website;
        $startedAt = now();
        $seen = [];
        $scannedPageIds = [];
        $scanned = 0;

        $scan->update([
            'status' => Scan::STATUS_RUNNING,
            'started_at' => $startedAt,
            'progress' => 1,
            'error_message' => null,
        ]);

        try {
            foreach ($this->crawler->crawl($website) as $document) {
                $page = $this->persistPage($scan, $website, $document);
                $scannedPageIds[] = $page->id;
                $results = $this->detectors->analyze($document, $scan->type);
                $snapshotPath = $results === [] ? null : $this->evidence->storePageSnapshot($scan, $page, $document);
                if ($snapshotPath !== null) {
                    $page->update(['snapshot_path' => $snapshotPath]);
                }

                foreach ($results as $result) {
                    $finding = $this->persistFinding($scan, $website, $page, $document, $result);
                    $seen[] = $finding->fingerprint;
                    if ($snapshotPath !== null) {
                        $this->evidence->attach($finding, $scan, $document, $snapshotPath, $result);
                    }
                }

                $scanned++;
                $maxPages = max(1, (int) config('maxguard.crawler.max_pages', 100));
                $scan->update([
                    'pages_discovered' => max($scan->pages_discovered, $scanned),
                    'pages_scanned' => $scanned,
                    'progress' => min(94, 5 + (int) floor(($scanned / $maxPages) * 89)),
                ]);
            }

            if ($scanned === 0) {
                throw new RuntimeException('No crawlable HTML pages were retrieved. Check DNS, robots.txt and website availability.');
            }

            $this->resolveMissingFindings($scan, $website, $startedAt, $seen, $scannedPageIds);
            $this->complete($scan, $website, $scanned);
        } catch (Throwable $exception) {
            $scan->update([
                'status' => Scan::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
            ]);
            $website->update(['status' => $website->last_scanned_at ? Website::statusFromScore($website->overall_score) : 'pending']);

            throw $exception;
        }
    }

    private function persistPage(Scan $scan, Website $website, PageDocument $document): Page
    {
        $hash = hash('sha256', $document->url);

        return Page::query()->updateOrCreate([
            'website_id' => $website->id,
            'url_hash' => $hash,
        ], [
            'last_scan_id' => $scan->id,
            'url' => $document->url,
            'canonical_url' => $document->canonicalUrl,
            'status_code' => $document->statusCode,
            'title' => mb_substr($document->title, 0, 255),
            'language' => $document->language,
            'content_hash' => $document->contentHash(),
            'word_count' => $document->wordCount,
            'ad_count' => $document->adCount,
            'last_scanned_at' => now(),
            'meta' => $document->meta,
        ]);
    }

    private function persistFinding(
        Scan $scan,
        Website $website,
        Page $page,
        PageDocument $document,
        DetectorResult $result,
    ): Finding {
        $fingerprint = $result->fingerprint($document->url);
        $finding = Finding::query()->firstOrNew([
            'website_id' => $website->id,
            'fingerprint' => $fingerprint,
        ]);
        $firstSeen = $finding->exists ? $finding->first_seen_at : now();
        $status = $finding->exists && in_array($finding->status, ['investigating', 'remediating'], true)
            ? $finding->status
            : 'open';

        $finding->fill([
            'scan_id' => $scan->id,
            'page_id' => $page->id,
            'rule_key' => $result->ruleKey,
            'category' => $result->category,
            'severity' => $result->severity,
            'confidence' => $result->confidence,
            'status' => $status,
            'title' => $result->title,
            'summary' => $result->summary,
            'policy_reference' => $result->policyReference,
            'revenue_impact' => $this->revenueImpact($website, $result->severity),
            'signals' => $result->signals,
            'remediation' => $result->remediation,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => now(),
            'resolved_at' => null,
        ]);
        $finding->save();

        return $finding;
    }

    /** @param list<string> $seen @param list<int> $scannedPageIds */
    private function resolveMissingFindings(Scan $scan, Website $website, mixed $startedAt, array $seen, array $scannedPageIds): void
    {
        $query = $website->findings()
            ->open()
            ->whereIn('page_id', array_values(array_unique($scannedPageIds)))
            ->where('last_seen_at', '<', $startedAt);
        if ($seen !== []) {
            $query->whereNotIn('fingerprint', array_values(array_unique($seen)));
        }

        $categories = match ($scan->type) {
            'copyright' => ['Copyright', 'Duplicate content'],
            'ads' => ['Ad experience'],
            'privacy' => ['Privacy & consent'],
            'priority' => [],
            default => null,
        };

        if ($categories === []) {
            return;
        }
        if (is_array($categories)) {
            $query->whereIn('category', $categories);
        }

        $query->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    private function complete(Scan $scan, Website $website, int $scanned): void
    {
        DB::transaction(function () use ($scan, $website, $scanned): void {
            $openFindings = $website->findings()->open()->get();
            $score = $this->riskScore->score($openFindings);

            $website->update([
                'status' => Website::statusFromScore($score),
                'overall_score' => $score,
                'pages_count' => $website->pages()->count(),
                'open_findings_count' => $openFindings->count(),
                'last_scanned_at' => now(),
                'next_scan_at' => now()->addDay(),
            ]);

            $scan->update([
                'status' => Scan::STATUS_COMPLETED,
                'progress' => 100,
                'pages_discovered' => $scanned,
                'pages_scanned' => $scanned,
                'findings_count' => $openFindings->count(),
                'score' => $score,
                'finished_at' => now(),
            ]);
        });
    }

    private function revenueImpact(Website $website, string $severity): float
    {
        $factor = match ($severity) {
            'critical' => 0.25,
            'high' => 0.12,
            'review' => 0.04,
            default => 0.01,
        };

        return round((float) $website->expected_monthly_revenue * $factor, 2);
    }
}
