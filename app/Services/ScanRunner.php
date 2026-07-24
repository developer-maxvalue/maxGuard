<?php

namespace App\Services;

use App\Data\CrawlPlan;
use App\Data\DetectorResult;
use App\Data\PageDocument;
use App\Jobs\FinalizeWebsiteScan;
use App\Jobs\RunScanPageBatch;
use App\Models\Finding;
use App\Models\Page;
use App\Models\Scan;
use App\Models\ScanTarget;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ScanRunner
{
    public function __construct(
        private WebsiteCrawler $crawler,
        private DetectorRegistry $detectors,
        private AiPolicyAnalyzer $ai,
        private EvidenceStore $evidence,
        private RiskScoreCalculator $riskScore,
    ) {
    }

    /**
     * Discover the requested sample once, persist it, then fan the work out to
     * small queue jobs. Re-entering this method is safe: existing queued
     * targets are dispatched again, while each page job atomically claims a
     * target before doing any work.
     */
    public function dispatchParallel(Scan $scan): void
    {
        $scan->refresh();
        if (in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL, Scan::STATUS_CANCELLED], true)) {
            return;
        }

        $scan->update([
            'status' => Scan::STATUS_RUNNING,
            'started_at' => $scan->started_at ?? now(),
            'progress' => max(1, (int) $scan->progress),
            'error_message' => null,
            'current_url' => null,
        ]);

        if (! $scan->targets()->exists()) {
            $plan = $this->crawler->discover($scan->website, $scan->max_urls ? (int) $scan->max_urls : null);
            if ($plan->count() === 0) {
                throw new RuntimeException('No crawlable URLs were discovered. Check the sitemap, robots.txt and website availability.');
            }

            $now = now();
            $batchSize = $this->pageBatchSize();
            $rows = [];
            foreach ($plan->urls as $position => $url) {
                $rows[] = [
                    'scan_id' => $scan->id,
                    'position' => $position,
                    'batch_number' => (int) floor($position / $batchSize) + 1,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                    'status' => ScanTarget::STATUS_QUEUED,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                ScanTarget::query()->insertOrIgnore($chunk);
            }

            $scan->update([
                'pages_discovered' => $plan->count(),
                'progress' => 5,
                'meta' => array_merge($plan->metadata(0), [
                    'parallel_scan' => true,
                    'page_batch_size' => $batchSize,
                    'recommended_page_workers' => max(1, (int) config('maxguard.recommended_page_workers', 6)),
                    'page_queue' => (string) config('maxguard.page_queue', 'scan-pages'),
                    'finalize_queue' => (string) config('maxguard.finalize_queue', 'scan-finalize'),
                    'batches_total' => (int) ceil($plan->count() / $batchSize),
                    'batches_completed' => 0,
                    'targets_queued' => $plan->count(),
                    'targets_running' => 0,
                    'targets_completed' => 0,
                    'targets_reused' => 0,
                    'targets_failed' => 0,
                    'incremental_scan' => ! $scan->force_rescan,
                    'ai_enabled' => (bool) $scan->use_ai,
                    'ai_model' => $scan->use_ai ? (string) config('maxguard.ai.model') : null,
                    'ai_page_limit' => max(0, (int) config('maxguard.ai.max_pages_per_scan', 100)),
                ]),
            ]);
        }

        $this->dispatchQueuedTargetBatches($scan->id);
        FinalizeWebsiteScan::dispatch($scan->id)
            ->onQueue((string) config('maxguard.finalize_queue', 'scan-finalize'));
    }

    /** @param list<int> $targetIds */
    public function runParallelBatch(int $scanId, array $targetIds, string $claimToken): void
    {
        $scan = Scan::query()->with('website')->findOrFail($scanId);
        if (in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL, Scan::STATUS_CANCELLED, Scan::STATUS_FAILED], true)) {
            return;
        }

        $claimedIds = [];
        foreach (array_values(array_unique(array_map('intval', $targetIds))) as $targetId) {
            $claimed = ScanTarget::query()
                ->whereKey($targetId)
                ->where('scan_id', $scan->id)
                ->where(function ($query) use ($claimToken): void {
                    $query->where('status', ScanTarget::STATUS_QUEUED)
                        ->orWhere(function ($query) use ($claimToken): void {
                            $query->where('status', ScanTarget::STATUS_RUNNING)
                                ->where('claim_token', $claimToken);
                        });
                })
                ->update([
                    'status' => ScanTarget::STATUS_RUNNING,
                    'claim_token' => $claimToken,
                    'attempts' => DB::raw('attempts + 1'),
                    'started_at' => now(),
                    'error_message' => null,
                    'updated_at' => now(),
                ]);
            if ($claimed === 1) {
                $claimedIds[] = $targetId;
            }
        }

        if ($claimedIds === []) {
            return;
        }

        $targets = ScanTarget::query()
            ->where('scan_id', $scan->id)
            ->whereIn('id', $claimedIds)
            ->orderBy('position')
            ->get();
        $targetByHash = $targets->keyBy('url_hash');
        $plan = new CrawlPlan(max(1, $targets->count()), max(1, $targets->count()));
        $plan->configureSelection('parallel_batch', $targets->count(), $targets->count(), true);
        foreach ($targets as $target) {
            $plan->addUrl($target->url, 'parallel_batch');
        }

        $processed = [];
        try {
            foreach ($this->crawler->crawl($scan->website, $plan) as $document) {
                $sourceUrl = (string) ($document->meta['crawl_source_url'] ?? $document->url);
                /** @var ScanTarget|null $target */
                $target = $targetByHash->get(hash('sha256', $sourceUrl));
                if ($target === null || isset($processed[$target->id])) {
                    continue;
                }

                try {
                    $this->processParallelDocument($scan, $target, $document);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->failTarget($target, $exception->getMessage());
                }
                $processed[$target->id] = true;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        foreach ($targets as $target) {
            if (! isset($processed[$target->id])) {
                $this->failTarget($target, 'The crawler did not return a crawlable HTML document for this URL.');
            }
        }

        $this->mergeBatchCrawlMetrics($scan->id, $plan);
        $this->refreshParallelProgress($scan->id);

        FinalizeWebsiteScan::dispatch($scan->id)
            ->onQueue((string) config('maxguard.finalize_queue', 'scan-finalize'));
    }

    public function finalizeParallel(int $scanId): bool
    {
        $scan = Scan::query()->with('website')->findOrFail($scanId);
        if (in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL, Scan::STATUS_CANCELLED, Scan::STATUS_FAILED], true)) {
            return true;
        }

        if ($scan->targets()->whereIn('status', [ScanTarget::STATUS_QUEUED, ScanTarget::STATUS_RUNNING])->exists()) {
            $this->refreshParallelProgress($scan->id);

            return false;
        }

        $successfulTargets = $scan->targets()
            ->with('page')
            ->whereIn('status', [ScanTarget::STATUS_COMPLETED, ScanTarget::STATUS_REUSED])
            ->orderBy('position')
            ->get();
        if ($successfulTargets->isEmpty()) {
            $scan->update([
                'status' => Scan::STATUS_FAILED,
                'progress' => 100,
                'finished_at' => now(),
                'current_url' => null,
                'error_message' => 'No crawlable HTML pages were retrieved. Check DNS, robots.txt and website availability.',
            ]);
            $scan->website->update([
                'status' => $scan->website->last_scanned_at
                    ? Website::statusFromScore($scan->website->overall_score)
                    : 'pending',
            ]);

            return true;
        }

        $this->detectors->resetDuplicateAnalysis();
        foreach ($successfulTargets as $target) {
            if ($target->page === null) {
                continue;
            }
            $sketch = data_get($target->page->meta, 'maxguard_duplicate_sketch', []);
            if (! is_array($sketch)) {
                continue;
            }
            foreach ($this->detectors->analyzeDuplicateSketch($target->page->url, $sketch, $scan->type) as $result) {
                $finding = $this->persistFindingForUrl($scan, $scan->website, $target->page, $target->page->url, $result);
                $this->evidence->attachSignalOnly($finding, $scan, $target->page->url, $result);
            }
        }

        $scan->refresh();
        $successfulPageIds = $successfulTargets->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $analyzedPageIds = $successfulTargets->where('status', ScanTarget::STATUS_COMPLETED)
            ->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $aiPageIds = $successfulTargets->where('ai_analyzed', true)
            ->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $seen = $scan->findings()->pluck('fingerprint')->all();
        $this->resolveMissingFindings(
            $scan,
            $scan->website,
            $scan->started_at ?? $scan->created_at,
            $seen,
            $analyzedPageIds,
            $aiPageIds,
        );
        if (in_array($scan->type, ['full', 'copyright'], true)) {
            $this->resolveStaleDuplicateFindings(
                $scan->website,
                $scan->started_at ?? $scan->created_at,
                $seen,
                $successfulPageIds,
            );
        }

        $this->completeParallel($scan, $successfulTargets->count());

        return true;
    }

    public function run(Scan $scan): void
    {
        $scan->refresh();
        if (in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_CANCELLED], true)) {
            return;
        }

        $website = $scan->website;
        $startedAt = now();
        $seen = [];
        $scannedPageIds = [];
        $aiScannedPageIds = [];
        $scanned = 0;
        $skippedUnchanged = 0;
        $aiAnalyzed = 0;
        $aiFindings = 0;
        $aiErrors = 0;
        $aiInputTokens = 0;
        $aiOutputTokens = 0;
        $scanFindingIds = [];

        $scan->update([
            'status' => Scan::STATUS_RUNNING,
            'started_at' => $startedAt,
            'progress' => 1,
            'error_message' => null,
            'current_url' => null,
        ]);

        try {
            $plan = $this->crawler->discover($website, $scan->max_urls ? (int) $scan->max_urls : null);
            $scan->update([
                'pages_discovered' => $plan->count(),
                'progress' => 5,
                'meta' => $plan->metadata(0),
            ]);

            foreach ($this->crawler->crawl($website, $plan) as $document) {
                $scan->update(['current_url' => $document->url]);
                $existingPage = $this->existingPage($website, $document);
                $reuseAnalysis = $this->canReuseAnalysis($scan, $existingPage, $document);
                $existingMarker = $reuseAnalysis ? data_get($existingPage?->meta, 'maxguard_analysis') : null;
                $duplicateSketch = $this->detectors->duplicateSketch($document);
                $page = $this->persistPage(
                    $scan,
                    $website,
                    $document,
                    is_array($existingMarker) ? $existingMarker : null,
                    $duplicateSketch,
                );

                if ($reuseAnalysis) {
                    $this->detectors->warmReusablePage($document, $scan->type);
                    $scanned++;
                    $skippedUnchanged++;
                    $this->updateProgress(
                        $scan,
                        $plan,
                        $scanned,
                        $skippedUnchanged,
                        $aiAnalyzed,
                        $aiFindings,
                        $aiErrors,
                        $aiInputTokens,
                        $aiOutputTokens,
                        count($scanFindingIds),
                    );

                    continue;
                }

                $scannedPageIds[] = $page->id;
                $results = $this->detectors->analyze($document, $scan->type);
                $aiAnalyzedThisPage = false;

                if ($this->shouldRunAi($scan, $aiAnalyzed)) {
                    $outcome = $this->ai->analyze($document);
                    if ($outcome->attempted) {
                        $aiAnalyzed++;
                        $aiInputTokens += $outcome->inputTokens;
                        $aiOutputTokens += $outcome->outputTokens;
                        if ($outcome->error !== null) {
                            $aiErrors++;
                        } else {
                            $aiAnalyzedThisPage = true;
                            $aiScannedPageIds[] = $page->id;
                            $aiResults = $this->detectors->filter($outcome->findings, $scan->type);
                            $aiFindings += count($aiResults);
                            $results = array_merge($results, $aiResults);
                        }
                    }
                }

                $snapshotPath = $results === [] ? null : $this->evidence->storePageSnapshot($scan, $page, $document);
                if ($snapshotPath !== null) {
                    $page->update(['snapshot_path' => $snapshotPath]);
                }

                foreach ($results as $result) {
                    $finding = $this->persistFinding($scan, $website, $page, $document, $result);
                    $seen[] = $finding->fingerprint;
                    $scanFindingIds[$finding->id] = true;
                    if ($snapshotPath !== null) {
                        $this->evidence->attach($finding, $scan, $document, $snapshotPath, $result);
                    }
                }

                $this->markPageAnalyzed($page, $scan, $aiAnalyzedThisPage);

                $scanned++;
                $this->updateProgress(
                    $scan,
                    $plan,
                    $scanned,
                    $skippedUnchanged,
                    $aiAnalyzed,
                    $aiFindings,
                    $aiErrors,
                    $aiInputTokens,
                    $aiOutputTokens,
                    count($scanFindingIds),
                );
            }

            if ($scanned === 0) {
                throw new RuntimeException('No crawlable HTML pages were retrieved. Check DNS, robots.txt and website availability.');
            }

            $this->resolveMissingFindings($scan, $website, $startedAt, $seen, $scannedPageIds, $aiScannedPageIds);
            $this->complete($scan, $website, $scanned, $plan);
        } catch (Throwable $exception) {
            $scan->update([
                'status' => Scan::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'current_url' => null,
            ]);
            $website->update(['status' => $website->last_scanned_at ? Website::statusFromScore($website->overall_score) : 'pending']);

            throw $exception;
        }
    }

    private function processParallelDocument(Scan $scan, ScanTarget $target, PageDocument $document): void
    {
        $website = $scan->website;
        $existingPage = $this->existingPage($website, $document);
        $reuseAnalysis = $this->canReuseAnalysis($scan, $existingPage, $document);
        $existingMarker = $reuseAnalysis ? data_get($existingPage?->meta, 'maxguard_analysis') : null;
        $duplicateSketch = $this->detectors->duplicateSketch($document);
        $page = $this->persistPage(
            $scan,
            $website,
            $document,
            is_array($existingMarker) ? $existingMarker : null,
            $duplicateSketch,
        );

        if ($reuseAnalysis) {
            $target->update([
                'page_id' => $page->id,
                'status' => ScanTarget::STATUS_REUSED,
                'analysis_reused' => true,
                'findings_count' => 0,
                'finished_at' => now(),
            ]);
            $this->recordParallelSuccess($scan->id, $document->url, true, 0, 0);

            return;
        }

        $results = $this->detectors->analyze($document, $scan->type, false);
        $aiAttempted = false;
        $aiAnalyzed = false;
        $aiFindings = 0;
        $aiInputTokens = 0;
        $aiOutputTokens = 0;
        $aiError = null;

        if ($target->ai_attempted) {
            $aiAttempted = true;
            $aiError = 'Previous AI attempt was interrupted; it was not repeated to avoid duplicate API cost.';
        } elseif ($this->reserveParallelAi($scan->id)) {
            $target->update(['ai_attempted' => true]);
            $aiAttempted = true;
            $outcome = $this->ai->analyze($document);
            $aiInputTokens = $outcome->inputTokens;
            $aiOutputTokens = $outcome->outputTokens;
            $aiError = $outcome->error;
            if ($outcome->attempted && $outcome->error === null) {
                $aiAnalyzed = true;
                $aiResults = $this->detectors->filter($outcome->findings, $scan->type);
                $aiFindings = count($aiResults);
                $results = array_merge($results, $aiResults);
            }
        }

        $snapshotPath = $results === [] ? null : $this->evidence->storePageSnapshot($scan, $page, $document);
        if ($snapshotPath !== null) {
            $page->update(['snapshot_path' => $snapshotPath]);
        }

        foreach ($results as $result) {
            $finding = $this->persistFinding($scan, $website, $page, $document, $result);
            if ($snapshotPath !== null) {
                $this->evidence->attach($finding, $scan, $document, $snapshotPath, $result);
            }
        }

        $this->markPageAnalyzed($page, $scan, $aiAnalyzed);
        $target->update([
            'page_id' => $page->id,
            'status' => ScanTarget::STATUS_COMPLETED,
            'analysis_reused' => false,
            'ai_attempted' => $aiAttempted,
            'ai_analyzed' => $aiAnalyzed,
            'findings_count' => count($results),
            'ai_findings_count' => $aiFindings,
            'ai_input_tokens' => $aiInputTokens,
            'ai_output_tokens' => $aiOutputTokens,
            'error_message' => $aiError === null ? null : mb_substr('AI: '.$aiError, 0, 5000),
            'finished_at' => now(),
        ]);
        $this->recordParallelSuccess($scan->id, $document->url, false, count($results), $aiFindings);
    }

    private function reserveParallelAi(int $scanId): bool
    {
        if (! $this->ai->isConfigured()) {
            return false;
        }

        return DB::transaction(function () use ($scanId): bool {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);
            if (! $scan->use_ai || in_array($scan->status, [Scan::STATUS_CANCELLED, Scan::STATUS_FAILED], true)) {
                return false;
            }

            $limit = max(0, (int) config('maxguard.ai.max_pages_per_scan', 100));
            $reserved = (int) $scan->ai_pages_analyzed;
            if ($limit > 0 && $reserved >= $limit) {
                return false;
            }

            $updates = ['ai_pages_analyzed' => $reserved + 1];
            if ($limit > 0 && $reserved + 1 >= $limit) {
                $updates['meta'] = array_merge((array) $scan->meta, ['ai_limit_reached' => true]);
            }
            $scan->update($updates);

            return true;
        });
    }

    private function recordParallelSuccess(
        int $scanId,
        string $url,
        bool $reused,
        int $findings,
        int $aiFindings,
    ): void {
        DB::transaction(function () use ($scanId, $url, $reused, $findings, $aiFindings): void {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);
            if (in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL, Scan::STATUS_CANCELLED, Scan::STATUS_FAILED], true)) {
                return;
            }
            $scanned = (int) $scan->pages_scanned + 1;
            $discovered = max(1, (int) $scan->pages_discovered);
            $progress = min(94, 5 + (int) floor(($scanned / $discovered) * 89));
            $scan->update([
                'pages_scanned' => $scanned,
                'pages_skipped_unchanged' => (int) $scan->pages_skipped_unchanged + ($reused ? 1 : 0),
                'findings_count' => (int) $scan->findings_count + $findings,
                'ai_findings_count' => (int) $scan->ai_findings_count + $aiFindings,
                'progress' => max((int) $scan->progress, $progress),
                'current_url' => $url,
            ]);
        });
    }

    private function failTarget(ScanTarget $target, string $message): void
    {
        ScanTarget::query()
            ->whereKey($target->id)
            ->where('status', ScanTarget::STATUS_RUNNING)
            ->update([
                'status' => ScanTarget::STATUS_FAILED,
                'error_message' => mb_substr($message, 0, 5000),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function mergeBatchCrawlMetrics(int $scanId, CrawlPlan $plan): void
    {
        DB::transaction(function () use ($scanId, $plan): void {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);
            $meta = (array) $scan->meta;
            foreach ([
                'blocked_by_robots' => $plan->blockedByRobots,
                'failed_requests' => $plan->failedRequests,
                'non_html_responses' => $plan->nonHtmlResponses,
            ] as $key => $increment) {
                $meta[$key] = (int) ($meta[$key] ?? 0) + $increment;
            }
            $scan->update(['meta' => $meta]);
        });
    }

    private function refreshParallelProgress(int $scanId): void
    {
        DB::transaction(function () use ($scanId): void {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);
            $counts = ScanTarget::query()
                ->where('scan_id', $scanId)
                ->selectRaw('status, COUNT(*) AS aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');
            $queued = (int) ($counts[ScanTarget::STATUS_QUEUED] ?? 0);
            $running = (int) ($counts[ScanTarget::STATUS_RUNNING] ?? 0);
            $completed = (int) ($counts[ScanTarget::STATUS_COMPLETED] ?? 0);
            $reused = (int) ($counts[ScanTarget::STATUS_REUSED] ?? 0);
            $failed = (int) ($counts[ScanTarget::STATUS_FAILED] ?? 0);
            $terminal = $completed + $reused + $failed;
            $discovered = max(1, (int) $scan->pages_discovered);
            $batchesTotal = ScanTarget::query()->where('scan_id', $scanId)->distinct()->count('batch_number');
            $incompleteBatches = ScanTarget::query()
                ->where('scan_id', $scanId)
                ->whereIn('status', [ScanTarget::STATUS_QUEUED, ScanTarget::STATUS_RUNNING])
                ->distinct()
                ->count('batch_number');
            $progress = min(94, 5 + (int) floor(($terminal / $discovered) * 89));
            $targets = ScanTarget::query()->where('scan_id', $scanId);
            $meta = array_merge((array) $scan->meta, [
                'targets_queued' => $queued,
                'targets_running' => $running,
                'targets_completed' => $completed,
                'targets_reused' => $reused,
                'targets_failed' => $failed,
                'batches_total' => $batchesTotal,
                'batches_completed' => max(0, $batchesTotal - $incompleteBatches),
                'pages_analyzed' => $completed,
                'pages_skipped_unchanged' => $reused,
                'coverage_percent' => round((($completed + $reused) / $discovered) * 100, 2),
                'ai_errors' => (clone $targets)->where('ai_attempted', true)->where('ai_analyzed', false)->count(),
                'ai_input_tokens' => (int) (clone $targets)->sum('ai_input_tokens'),
                'ai_output_tokens' => (int) (clone $targets)->sum('ai_output_tokens'),
            ]);

            $scan->update([
                'pages_scanned' => $completed + $reused,
                'pages_skipped_unchanged' => $reused,
                'ai_findings_count' => (int) (clone $targets)->sum('ai_findings_count'),
                'findings_count' => (int) (clone $targets)->sum('findings_count'),
                'progress' => max((int) $scan->progress, $progress),
                'meta' => $meta,
            ]);
        });
    }

    private function completeParallel(Scan $scan, int $scanned): void
    {
        $this->refreshParallelProgress($scan->id);
        DB::transaction(function () use ($scan, $scanned): void {
            $lockedScan = Scan::query()->lockForUpdate()->findOrFail($scan->id);
            $website = Website::query()->lockForUpdate()->findOrFail($lockedScan->website_id);
            $openFindings = $website->findings()->open()->get();
            $score = $this->riskScore->score($openFindings);
            $scanFindingsCount = $lockedScan->findings()->count();
            $meta = (array) $lockedScan->meta;
            $failed = (int) ($meta['targets_failed'] ?? 0);
            $discovered = max($scanned + $failed, (int) $lockedScan->pages_discovered);
            $partial = (bool) ($meta['discovery_truncated'] ?? false)
                || (int) ($meta['sitemap_errors'] ?? 0) > 0
                || ($meta['discovery_confidence'] ?? 'low') === 'low'
                || $failed > 0
                || $scanned < $discovered;
            $aiAttempted = $lockedScan->targets()->where('ai_attempted', true)->count();
            $aiLimit = max(0, (int) config('maxguard.ai.max_pages_per_scan', 100));
            $meta = array_merge($meta, [
                'coverage_percent' => $discovered > 0 ? round(($scanned / $discovered) * 100, 2) : 0,
                'ai_limit_reached' => $aiLimit > 0
                    && $aiAttempted >= $aiLimit
                    && $scanned > $aiAttempted + (int) $lockedScan->pages_skipped_unchanged,
                'parallel_finalized_at' => now()->toIso8601String(),
            ]);

            $website->update([
                'status' => Website::statusFromScore($score),
                'overall_score' => $score,
                'pages_count' => $website->pages()->count(),
                'last_discovered_pages' => $discovered,
                'last_scanned_pages' => $scanned,
                'last_scan_partial' => $partial,
                'open_findings_count' => $openFindings->count(),
                'last_scanned_at' => now(),
                'next_scan_at' => now()->addDay(),
            ]);
            $lockedScan->update([
                'status' => $partial ? Scan::STATUS_PARTIAL : Scan::STATUS_COMPLETED,
                'progress' => 100,
                'pages_discovered' => $discovered,
                'pages_scanned' => $scanned,
                'ai_pages_analyzed' => $aiAttempted,
                'findings_count' => $scanFindingsCount,
                'score' => $score,
                'finished_at' => now(),
                'current_url' => null,
                'meta' => $meta,
            ]);
        });
    }

    /** @param list<string> $seen @param list<int> $pageIds */
    private function resolveStaleDuplicateFindings(
        Website $website,
        mixed $startedAt,
        array $seen,
        array $pageIds,
    ): void {
        if ($pageIds === []) {
            return;
        }

        $query = $website->findings()
            ->open()
            ->whereIn('page_id', array_values(array_unique($pageIds)))
            ->where('rule_key', 'like', 'duplicate.%')
            ->where('last_seen_at', '<', $startedAt);
        if ($seen !== []) {
            $query->whereNotIn('fingerprint', array_values(array_unique($seen)));
        }
        $query->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    private function dispatchQueuedTargetBatches(int $scanId): void
    {
        ScanTarget::query()
            ->where('scan_id', $scanId)
            ->where('status', ScanTarget::STATUS_QUEUED)
            ->orderBy('position')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->chunk($this->pageBatchSize())
            ->each(function ($ids) use ($scanId): void {
                RunScanPageBatch::dispatch($scanId, $ids->values()->all())
                    ->onQueue((string) config('maxguard.page_queue', 'scan-pages'));
            });
    }

    private function pageBatchSize(): int
    {
        return max(1, min(100, (int) config('maxguard.page_batch_size', 10)));
    }

    private function shouldRunAi(Scan $scan, int $analyzed): bool
    {
        if (! $scan->use_ai || ! $this->ai->isConfigured()) {
            return false;
        }

        $limit = max(0, (int) config('maxguard.ai.max_pages_per_scan', 100));

        return $limit === 0 || $analyzed < $limit;
    }

    private function existingPage(Website $website, PageDocument $document): ?Page
    {
        return Page::query()
            ->where('website_id', $website->id)
            ->where('url_hash', hash('sha256', $document->url))
            ->first();
    }

    private function canReuseAnalysis(Scan $scan, ?Page $page, PageDocument $document): bool
    {
        if ($scan->force_rescan || $page === null || ! hash_equals((string) $page->content_hash, $document->contentHash())) {
            return false;
        }

        $marker = data_get($page->meta, 'maxguard_analysis');
        if (! is_array($marker) || ($marker['ruleset_version'] ?? null) !== $scan->ruleset_version) {
            return false;
        }
        if (! $this->scanScopeCovers((string) ($marker['scan_type'] ?? ''), $scan->type)) {
            return false;
        }
        if ($scan->use_ai) {
            return ($marker['ai_analyzed'] ?? false) === true
                && ($marker['ai_model'] ?? null) === (string) config('maxguard.ai.model');
        }

        return true;
    }

    private function scanScopeCovers(string $previousType, string $requestedType): bool
    {
        if ($previousType === 'full') {
            return true;
        }

        return $previousType === $requestedType;
    }

    /** @param array<string, mixed>|null $analysisMarker @param array<string, true>|null $duplicateSketch */
    private function persistPage(
        Scan $scan,
        Website $website,
        PageDocument $document,
        ?array $analysisMarker = null,
        ?array $duplicateSketch = null,
    ): Page
    {
        $hash = hash('sha256', $document->url);
        $meta = $document->meta;
        if ($analysisMarker !== null) {
            $meta['maxguard_analysis'] = $analysisMarker;
        }
        if ($duplicateSketch !== null) {
            $meta['maxguard_duplicate_sketch'] = $duplicateSketch;
        }

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
            'meta' => $meta,
        ]);
    }

    private function markPageAnalyzed(Page $page, Scan $scan, bool $aiAnalyzed): void
    {
        $meta = (array) $page->meta;
        $meta['maxguard_analysis'] = [
            'ruleset_version' => $scan->ruleset_version,
            'scan_type' => $scan->type,
            'ai_analyzed' => $aiAnalyzed,
            'ai_model' => $aiAnalyzed ? (string) config('maxguard.ai.model') : null,
            'analyzed_at' => now()->toIso8601String(),
        ];
        $page->update(['meta' => $meta]);
    }

    private function updateProgress(
        Scan $scan,
        CrawlPlan $plan,
        int $scanned,
        int $skippedUnchanged,
        int $aiAnalyzed,
        int $aiFindings,
        int $aiErrors,
        int $aiInputTokens,
        int $aiOutputTokens,
        int $findingsCount,
    ): void {
        $discovered = max($scanned, $plan->count());
        $progress = min(94, 5 + (int) floor(($scanned / $discovered) * 89));
        $scan->update([
            'pages_discovered' => $discovered,
            'pages_scanned' => $scanned,
            'pages_skipped_unchanged' => $skippedUnchanged,
            'ai_pages_analyzed' => $aiAnalyzed,
            'ai_findings_count' => $aiFindings,
            'findings_count' => $findingsCount,
            'progress' => max($scan->progress, $progress),
            'meta' => array_merge($plan->metadata($scanned), [
                'incremental_scan' => ! $scan->force_rescan,
                'pages_skipped_unchanged' => $skippedUnchanged,
                'pages_analyzed' => max(0, $scanned - $skippedUnchanged),
                'ai_enabled' => (bool) $scan->use_ai,
                'ai_model' => $scan->use_ai ? (string) config('maxguard.ai.model') : null,
                'ai_errors' => $aiErrors,
                'ai_input_tokens' => $aiInputTokens,
                'ai_output_tokens' => $aiOutputTokens,
                'ai_page_limit' => max(0, (int) config('maxguard.ai.max_pages_per_scan', 100)),
                'ai_limit_reached' => (int) config('maxguard.ai.max_pages_per_scan', 100) > 0
                    && $aiAnalyzed >= (int) config('maxguard.ai.max_pages_per_scan', 100)
                    && max($scanned, $plan->count()) > $aiAnalyzed + $skippedUnchanged,
            ]),
        ]);
    }

    private function persistFinding(
        Scan $scan,
        Website $website,
        Page $page,
        PageDocument $document,
        DetectorResult $result,
    ): Finding {
        return $this->persistFindingForUrl($scan, $website, $page, $document->url, $result);
    }

    private function persistFindingForUrl(
        Scan $scan,
        Website $website,
        Page $page,
        string $url,
        DetectorResult $result,
    ): Finding {
        $fingerprint = $result->fingerprint($url);
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

    /** @param list<string> $seen @param list<int> $scannedPageIds @param list<int> $aiScannedPageIds */
    private function resolveMissingFindings(
        Scan $scan,
        Website $website,
        mixed $startedAt,
        array $seen,
        array $scannedPageIds,
        array $aiScannedPageIds,
    ): void
    {
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

        $this->resolveFindingsQuery(
            $website,
            $startedAt,
            $seen,
            $scannedPageIds,
            $categories,
            false,
        );

        if ($scan->use_ai && $aiScannedPageIds !== []) {
            $this->resolveFindingsQuery(
                $website,
                $startedAt,
                $seen,
                $aiScannedPageIds,
                $categories,
                true,
            );
        }
    }

    /** @param list<string> $seen @param list<int> $pageIds @param list<string>|null $categories */
    private function resolveFindingsQuery(
        Website $website,
        mixed $startedAt,
        array $seen,
        array $pageIds,
        ?array $categories,
        bool $aiOnly,
    ): void {
        if ($pageIds === []) {
            return;
        }

        $query = $website->findings()
            ->open()
            ->whereIn('page_id', array_values(array_unique($pageIds)))
            ->where('last_seen_at', '<', $startedAt)
            ->where('rule_key', $aiOnly ? 'like' : 'not like', 'ai.%');
        if ($seen !== []) {
            $query->whereNotIn('fingerprint', array_values(array_unique($seen)));
        }
        if (is_array($categories)) {
            $query->whereIn('category', $categories);
        }

        $query->update(['status' => 'resolved', 'resolved_at' => now()]);
    }

    private function complete(Scan $scan, Website $website, int $scanned, CrawlPlan $plan): void
    {
        DB::transaction(function () use ($scan, $website, $scanned, $plan): void {
            $openFindings = $website->findings()->open()->get();
            $scanFindingsCount = $scan->findings()->count();
            $score = $this->riskScore->score($openFindings);
            $discovered = max($scanned, $plan->count());
            $partial = $plan->truncated
                || $plan->sitemapErrors > 0
                || $plan->discoveryConfidence() === 'low'
                || $scanned < $discovered;

            $website->update([
                'status' => Website::statusFromScore($score),
                'overall_score' => $score,
                'pages_count' => $website->pages()->count(),
                'last_discovered_pages' => $discovered,
                'last_scanned_pages' => $scanned,
                'last_scan_partial' => $partial,
                'open_findings_count' => $openFindings->count(),
                'last_scanned_at' => now(),
                'next_scan_at' => now()->addDay(),
            ]);

            $scan->update([
                'status' => $partial ? Scan::STATUS_PARTIAL : Scan::STATUS_COMPLETED,
                'progress' => 100,
                'pages_discovered' => $discovered,
                'pages_scanned' => $scanned,
                'findings_count' => $scanFindingsCount,
                'score' => $score,
                'finished_at' => now(),
                'current_url' => null,
                'meta' => array_merge((array) $scan->meta, $plan->metadata($scanned)),
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
