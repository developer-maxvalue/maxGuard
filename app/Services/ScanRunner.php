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
        private RiskScoreCalculator $riskScore,
    ) {}

    /**
     * Discover the requested sample once, persist it, then fan the work out to
     * small queue jobs. Re-entering this method is safe: existing queued
     * targets are dispatched again, while each page job atomically claims a
     * target before doing any work.
     */
    public function dispatchParallel(Scan $scan): void
    {
        app(AiConfiguration::class)->apply();
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
            $this->prioritizeWithGa4($scan, $plan);
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
        app(AiConfiguration::class)->apply();
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
                    'current_stage' => 'crawl',
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
                $this->failTarget(
                    $target,
                    $plan->errorFor($target->url)
                        ?? 'The crawler did not return a crawlable HTML document for this URL.',
                );
            }
        }

        $this->mergeBatchCrawlMetrics($scan->id, $plan);
        $this->refreshParallelProgress($scan->id);

        FinalizeWebsiteScan::dispatch($scan->id)
            ->onQueue((string) config('maxguard.finalize_queue', 'scan-finalize'));
    }

    public function finalizeParallel(int $scanId): bool
    {
        app(AiConfiguration::class)->apply();
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
                $this->persistFindingForUrl($scan, $scan->website, $target->page, $target->page->url, $result);
            }
        }

        $scan->refresh();
        $successfulPageIds = $successfulTargets->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $analyzedPageIds = $successfulTargets->where('status', ScanTarget::STATUS_COMPLETED)
            ->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $aiPageIds = $successfulTargets->where('ai_analyzed', true)
            ->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $browserPageIds = $successfulTargets->where('status', ScanTarget::STATUS_COMPLETED)
            ->filter(fn (ScanTarget $target): bool => (bool) data_get($target->page?->meta, 'maxguard_analysis.browser_audited', false))
            ->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $externalCopyPageIds = $successfulTargets->where('status', ScanTarget::STATUS_COMPLETED)
            ->filter(fn (ScanTarget $target): bool => (bool) data_get($target->page?->meta, 'maxguard_analysis.external_copy_checked', false))
            ->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $seen = $scan->findings()->pluck('fingerprint')->all();
        $this->resolveMissingFindings(
            $scan,
            $scan->website,
            $scan->started_at ?? $scan->created_at,
            $seen,
            $analyzedPageIds,
            $aiPageIds,
            $browserPageIds,
            $externalCopyPageIds,
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
        $this->generateSiteAssessment($scan->fresh());

        return true;
    }

    public function run(Scan $scan): void
    {
        app(AiConfiguration::class)->apply();
        $scan->refresh();
        if (in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_CANCELLED], true)) {
            return;
        }

        $website = $scan->website;
        $startedAt = now();
        $seen = [];
        $scannedPageIds = [];
        $aiScannedPageIds = [];
        $browserScannedPageIds = [];
        $externalCopyScannedPageIds = [];
        $scanned = 0;
        $skippedUnchanged = 0;
        $aiAnalyzed = 0;
        $aiFindings = 0;
        $aiErrors = 0;
        $aiInputTokens = 0;
        $aiOutputTokens = 0;
        $browserAnalyzed = 0;
        $externalCopyAnalyzed = 0;
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
            $this->prioritizeWithGa4($scan, $plan);
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
                $results = array_merge(
                    $this->detectors->analyze($document, $scan->type),
                    app(SightengineTextAnalyzer::class)->analyze($document),
                );
                $browserAuditedThisPage = false;
                if ($this->shouldRunAddon($scan, 'browser_audit', $browserAnalyzed, ['full', 'ads', 'priority'])) {
                    $browserAuditor = app(BrowserAdAuditor::class);
                    $browserResults = $browserAuditor->analyze($document);
                    $results = array_merge($results, $this->detectors->filter($browserResults, $scan->type));
                    $browserAuditedThisPage = ! isset($browserAuditor->lastTrace()['error']);
                    $browserAnalyzed++;
                }
                $externalCopyReady = app(ExternalCopyAnalyzer::class)->isConfigured();
                $externalCopyCheckedThisPage = $externalCopyReady
                    && $document->wordCount < max(50, (int) config('maxguard.external_copy.minimum_words', 250));
                if ($document->wordCount >= max(50, (int) config('maxguard.external_copy.minimum_words', 250))
                    && $this->shouldRunAddon($scan, 'external_copy', $externalCopyAnalyzed, ['full', 'copyright', 'priority'])) {
                    $externalCopyAnalyzer = app(ExternalCopyAnalyzer::class);
                    $copyResults = $externalCopyAnalyzer->analyze($document);
                    $results = array_merge($results, $this->detectors->filter($copyResults, $scan->type));
                    $externalCopyCheckedThisPage = ! isset($externalCopyAnalyzer->lastTrace()['error']);
                    $externalCopyAnalyzed++;
                }
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

                foreach ($results as $result) {
                    $finding = $this->persistFinding($scan, $website, $page, $document, $result);
                    $seen[] = $finding->fingerprint;
                    $scanFindingIds[$finding->id] = true;
                }

                $this->markPageAnalyzed($page, $scan, $aiAnalyzedThisPage, $browserAuditedThisPage, $externalCopyCheckedThisPage);
                if ($browserAuditedThisPage) {
                    $browserScannedPageIds[] = $page->id;
                }
                if ($externalCopyCheckedThisPage) {
                    $externalCopyScannedPageIds[] = $page->id;
                }

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

            $this->resolveMissingFindings(
                $scan,
                $website,
                $startedAt,
                $seen,
                $scannedPageIds,
                $aiScannedPageIds,
                $browserScannedPageIds,
                $externalCopyScannedPageIds,
            );
            $this->complete($scan, $website, $scanned, $plan);
            $this->generateSiteAssessment($scan->fresh());
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
        $telemetry = app(ScanTelemetry::class);
        $crawlStarted = $telemetry->start($target, 'crawl', 'trình thu thập', 'Đã tải xuống và phân tích tài liệu HTML.');
        $telemetry->finish($target, 'crawl', $crawlStarted, 'success', 'Đã hoàn tất thu thập dữ liệu.', [
            'http_status' => $document->statusCode,
            'word_count' => $document->wordCount,
            'language' => $document->language,
            'content_hash' => $document->contentHash(),
        ]);

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
            $reuseStarted = $telemetry->start($target, 'reuse', 'bộ nhớ đệm tăng dần', 'Đang kiểm tra kết quả phân tích nội dung trước đó.');
            $telemetry->finish($target, 'reuse', $reuseStarted, 'reused', 'Mã băm nội dung và cấu hình phân tích không thay đổi; đã bỏ qua các bộ phân tích tính phí.', [
                'content_hash' => $document->contentHash(),
                'last_scan_id' => $existingPage?->last_scan_id,
            ]);
            $target->update([
                'page_id' => $page->id,
                'status' => ScanTarget::STATUS_REUSED,
                'current_stage' => 'finished',
                'analysis_reused' => true,
                'findings_count' => 0,
                'finished_at' => now(),
            ]);
            $this->recordParallelSuccess($scan->id, $document->url, true, 0, 0);

            return;
        }

        // Local rules run first; the external moderation result is normalized
        // into the same finding format and therefore appears on the same URL.
        $localStarted = $telemetry->start($target, 'local_rules', 'Bộ phát hiện cục bộ MaxGuard', 'Đang chạy các quy tắc về trùng lặp, cụm từ cảnh báo, bản quyền, chất lượng, quảng cáo, quyền riêng tư và kỹ thuật.');
        $localResults = $this->detectors->analyze($document, $scan->type, false);
        $telemetry->finish($target, 'local_rules', $localStarted, 'success', count($localResults).' phát hiện từ quy tắc cục bộ.', [
            'findings_count' => count($localResults),
            'rules' => array_values(array_map(fn (DetectorResult $result): string => $result->ruleKey, $localResults)),
        ]);

        $browserAudited = false;
        $browser = app(BrowserAdAuditor::class);
        $browserStarted = $telemetry->start($target, 'browser_audit', 'Playwright Chromium', 'Rendering desktop and mobile layouts to inspect real ad placement.');
        $browserResults = [];
        if ($this->reserveParallelAddon($scan->id, 'browser_audit', ['full', 'ads', 'priority'])) {
            $browserResults = $this->detectors->filter($browser->analyze($document), $scan->type);
            $browserAudited = ! isset($browser->lastTrace()['error']);
        }
        $browserTrace = $browser->lastTrace();
        $telemetry->finish(
            $target,
            'browser_audit',
            $browserStarted,
            isset($browserTrace['error']) ? 'failed' : (($browserTrace['attempted'] ?? false) ? 'success' : 'skipped'),
            (string) ($browserTrace['error'] ?? (($browserTrace['attempted'] ?? false) ? count($browserResults).' browser findings.' : 'Browser audit is disabled or has reached its page limit.')),
            $browserTrace + ['findings_count' => count($browserResults)],
        );

        $externalCopy = app(ExternalCopyAnalyzer::class);
        $externalCopyChecked = $externalCopy->isConfigured()
            && $document->wordCount < max(50, (int) config('maxguard.external_copy.minimum_words', 250));
        $copyStarted = $telemetry->start($target, 'external_copy', 'Tavily Search', 'Searching for and comparing similar content on other websites.');
        $copyResults = [];
        if ($document->wordCount >= max(50, (int) config('maxguard.external_copy.minimum_words', 250))
            && $this->reserveParallelAddon($scan->id, 'external_copy', ['full', 'copyright', 'priority'])) {
            $copyResults = $this->detectors->filter($externalCopy->analyze($document), $scan->type);
            $externalCopyChecked = ! isset($externalCopy->lastTrace()['error']);
        }
        $copyTrace = $externalCopy->lastTrace();
        $telemetry->finish(
            $target,
            'external_copy',
            $copyStarted,
            isset($copyTrace['error']) ? 'failed' : (($copyTrace['attempted'] ?? false) ? 'success' : 'skipped'),
            (string) ($copyTrace['error'] ?? (($copyTrace['attempted'] ?? false) ? count($copyResults).' external-copy findings.' : 'External-copy checking is unavailable, skipped, or at its page limit.')),
            $copyTrace + ['findings_count' => count($copyResults)],
        );

        $sightengine = app(SightengineTextAnalyzer::class);
        $thirdPartyStarted = $telemetry->start($target, 'sightengine', 'Sightengine', 'Đang gửi văn bản trang đến dịch vụ kiểm duyệt bên thứ ba.');
        $thirdPartyResults = $sightengine->analyze($document);
        $thirdPartyTrace = $sightengine->lastTrace();
        $thirdPartyStatus = isset($thirdPartyTrace['error']) ? 'failed' : (($thirdPartyTrace['attempted'] ?? false) ? 'success' : 'skipped');
        $externalErrors = isset($thirdPartyTrace['error'])
            ? ['Sightengine: '.(string) $thirdPartyTrace['error']]
            : [];
        $telemetry->finish(
            $target,
            'sightengine',
            $thirdPartyStarted,
            $thirdPartyStatus,
            isset($thirdPartyTrace['error'])
                ? (string) $thirdPartyTrace['error']
                : ((string) ($thirdPartyTrace['skipped_reason'] ?? count($thirdPartyResults).' phát hiện kiểm duyệt.')),
            $thirdPartyTrace + ['findings_count' => count($thirdPartyResults)],
        );
        $results = array_merge($localResults, $browserResults, $copyResults, $thirdPartyResults);
        $aiAttempted = false;
        $aiAnalyzed = false;
        $aiFindings = 0;
        $aiInputTokens = 0;
        $aiOutputTokens = 0;
        $aiError = null;

        if ($target->ai_attempted) {
            $aiAttempted = true;
            $aiError = 'Lần thử AI trước bị gián đoạn; hệ thống không thử lại để tránh phát sinh chi phí API trùng lặp.';
            $externalErrors[] = 'AI: '.$aiError;
        } elseif ($this->reserveParallelAi($scan->id)) {
            $target->update(['ai_attempted' => true]);
            $aiAttempted = true;
            $aiStarted = $telemetry->start($target, 'gemini', 'Gemini', 'Đang gửi nội dung trang đã chuẩn hóa để xem xét chính sách theo ngữ nghĩa.');
            $outcome = $this->ai->analyze($document);
            $aiInputTokens = $outcome->inputTokens;
            $aiOutputTokens = $outcome->outputTokens;
            $aiError = $outcome->error;
            if ($aiError !== null && $outcome->attempted) {
                $externalErrors[] = 'AI: '.$aiError;
            }
            if ($outcome->attempted && $outcome->error === null) {
                $aiAnalyzed = true;
                $aiResults = $this->detectors->filter($outcome->findings, $scan->type);
                $aiFindings = count($aiResults);
                $results = array_merge($results, $aiResults);
            }
            $telemetry->finish(
                $target,
                'gemini',
                $aiStarted,
                ! $outcome->attempted ? 'skipped' : ($outcome->error === null ? 'success' : 'failed'),
                $outcome->error ?? $aiFindings.' phát hiện từ AI.',
                [
                    'request_id' => $outcome->responseId,
                    'http_status' => $outcome->httpStatus,
                    'model' => $outcome->model,
                    'input_tokens' => $outcome->inputTokens,
                    'output_tokens' => $outcome->outputTokens,
                    'findings_count' => $aiFindings,
                ],
            );
        } else {
            $aiStarted = $telemetry->start($target, 'gemini', 'Gemini', 'Đang kiểm tra AI đã bật và còn trong giới hạn số trang hay không.');
            $telemetry->finish($target, 'gemini', $aiStarted, 'skipped', 'AI chưa bật, chưa được cấu hình hoặc đã đạt giới hạn số trang.');
        }

        foreach ($results as $result) {
            $this->persistFinding($scan, $website, $page, $document, $result);
        }

        $this->markPageAnalyzed($page, $scan, $aiAnalyzed, $browserAudited, $externalCopyChecked);
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
            'error_message' => $externalErrors === [] ? null : mb_substr(implode("\n", $externalErrors), 0, 5000),
            'debug_meta' => [
                'external_errors' => $externalErrors,
                'sightengine_attempted' => (bool) ($thirdPartyTrace['attempted'] ?? false),
                'sightengine_http_status' => $thirdPartyTrace['http_status'] ?? null,
                'sightengine_request_id' => $thirdPartyTrace['request_id'] ?? null,
                'browser_audit' => $browserTrace,
                'external_copy' => $copyTrace,
            ],
            'finished_at' => now(),
            'current_stage' => 'finished',
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

    /** @param list<string> $scanTypes */
    private function reserveParallelAddon(int $scanId, string $name, array $scanTypes): bool
    {
        $service = $name === 'browser_audit' ? app(BrowserAdAuditor::class) : app(ExternalCopyAnalyzer::class);
        if (! $service->isConfigured()) {
            return false;
        }

        return DB::transaction(function () use ($scanId, $name, $scanTypes): bool {
            $scan = Scan::query()->lockForUpdate()->findOrFail($scanId);
            if (! in_array($scan->type, $scanTypes, true) || in_array($scan->status, [Scan::STATUS_CANCELLED, Scan::STATUS_FAILED], true)) {
                return false;
            }
            $meta = (array) $scan->meta;
            $counter = $name.'_pages_analyzed';
            $reserved = (int) ($meta[$counter] ?? 0);
            $limit = max(0, (int) config("maxguard.{$name}.max_pages_per_scan", 0));
            if ($limit > 0 && $reserved >= $limit) {
                return false;
            }
            $meta[$counter] = $reserved + 1;
            if ($limit > 0 && $reserved + 1 >= $limit) {
                $meta[$name.'_limit_reached'] = true;
            }
            $scan->update(['meta' => $meta]);

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
        $started = app(ScanTelemetry::class)->start($target, 'pipeline', 'Trình chạy quét', 'Xử lý URL thất bại trước khi quy trình hoàn tất.');
        app(ScanTelemetry::class)->finish($target, 'pipeline', $started, 'failed', $message);
        ScanTarget::query()
            ->whereKey($target->id)
            ->where('status', ScanTarget::STATUS_RUNNING)
            ->update([
                'status' => ScanTarget::STATUS_FAILED,
                'error_message' => mb_substr($message, 0, 5000),
                'finished_at' => now(),
                'current_stage' => 'failed',
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

    /** @param list<string> $scanTypes */
    private function shouldRunAddon(Scan $scan, string $name, int $analyzed, array $scanTypes): bool
    {
        $service = $name === 'browser_audit' ? app(BrowserAdAuditor::class) : app(ExternalCopyAnalyzer::class);
        if (! $service->isConfigured() || ! in_array($scan->type, $scanTypes, true)) {
            return false;
        }
        $limit = max(0, (int) config("maxguard.{$name}.max_pages_per_scan", 0));

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
        if ($scan->use_ai
            && (($marker['ai_analyzed'] ?? false) !== true
                || ($marker['ai_model'] ?? null) !== (string) config('maxguard.ai.model'))) {
            return false;
        }
        if (app(BrowserAdAuditor::class)->isConfigured()
            && in_array($scan->type, ['full', 'ads', 'priority'], true)
            && ($marker['browser_audited'] ?? false) !== true) {
            return false;
        }
        if (app(ExternalCopyAnalyzer::class)->isConfigured()
            && in_array($scan->type, ['full', 'copyright', 'priority'], true)
            && ($marker['external_copy_checked'] ?? false) !== true) {
            return false;
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
    ): Page {
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

    private function markPageAnalyzed(
        Page $page,
        Scan $scan,
        bool $aiAnalyzed,
        bool $browserAudited = false,
        bool $externalCopyChecked = false,
    ): void {
        $meta = (array) $page->meta;
        $meta['maxguard_analysis'] = [
            'ruleset_version' => $scan->ruleset_version,
            'scan_type' => $scan->type,
            'ai_analyzed' => $aiAnalyzed,
            'ai_model' => $aiAnalyzed ? (string) config('maxguard.ai.model') : null,
            'browser_audited' => $browserAudited,
            'browser_audit_version' => $browserAudited ? 1 : null,
            'external_copy_checked' => $externalCopyChecked,
            'external_copy_version' => $externalCopyChecked ? 1 : null,
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
            'signals' => $result->signals,
            'remediation' => $result->remediation,
            'first_seen_at' => $firstSeen,
            'last_seen_at' => now(),
            'resolved_at' => null,
        ]);
        $finding->save();

        return $finding;
    }

    /**
     * @param  list<string>  $seen
     * @param  list<int>  $scannedPageIds
     * @param  list<int>  $aiScannedPageIds
     * @param  list<int>  $browserScannedPageIds
     * @param  list<int>  $externalCopyScannedPageIds
     */
    private function resolveMissingFindings(
        Scan $scan,
        Website $website,
        mixed $startedAt,
        array $seen,
        array $scannedPageIds,
        array $aiScannedPageIds,
        array $browserScannedPageIds = [],
        array $externalCopyScannedPageIds = [],
    ): void {
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
            'local',
        );

        if ($scan->use_ai && $aiScannedPageIds !== []) {
            $this->resolveFindingsQuery(
                $website,
                $startedAt,
                $seen,
                $aiScannedPageIds,
                $categories,
                'ai',
            );
        }
        $this->resolveFindingsQuery($website, $startedAt, $seen, $browserScannedPageIds, $categories, 'browser');
        $this->resolveFindingsQuery($website, $startedAt, $seen, $externalCopyScannedPageIds, $categories, 'external_copy');
    }

    /** @param list<string> $seen @param list<int> $pageIds @param list<string>|null $categories */
    private function resolveFindingsQuery(
        Website $website,
        mixed $startedAt,
        array $seen,
        array $pageIds,
        ?array $categories,
        string $scope,
    ): void {
        if ($pageIds === []) {
            return;
        }

        $query = $website->findings()
            ->open()
            ->whereIn('page_id', array_values(array_unique($pageIds)))
            ->where('last_seen_at', '<', $startedAt);
        match ($scope) {
            'ai' => $query->where('rule_key', 'like', 'ai.%'),
            'browser' => $query->where('rule_key', 'like', 'ads.browser-%'),
            'external_copy' => $query->where('rule_key', 'copyright.external-content-match'),
            default => $query
                ->where('rule_key', 'not like', 'ai.%')
                ->where('rule_key', 'not like', 'ads.browser-%')
                ->where('rule_key', '!=', 'copyright.external-content-match'),
        };
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

    private function generateSiteAssessment(Scan $scan): void
    {
        if (! app(AiConfiguration::class)->isReady()) {
            return;
        }

        try {
            app(WebsiteAiReviewer::class)->reviewAndStore($scan);
        } catch (Throwable $exception) {
            report($exception);
            $scan->update([
                'meta' => array_merge((array) $scan->meta, [
                    'ai_assessment_error' => mb_substr($exception->getMessage(), 0, 1000),
                    'ai_assessment_failed_at' => now()->toIso8601String(),
                ]),
            ]);
        }
    }

    /**
     * A priority scan uses GA4's seven-day order. Only URLs belonging to the
     * crawled site are retained; on API failure the sitemap order is preserved.
     */
    private function prioritizeWithGa4(Scan $scan, CrawlPlan $plan): void
    {
        if ($scan->type !== 'priority' || $scan->website->ga4Connection === null) {
            return;
        }
        try {
            $traffic = app(Ga4TrafficService::class)->sync($scan->website);
            $byPath = [];
            foreach ($plan->urls as $url) {
                $byPath[parse_url($url, PHP_URL_PATH) ?: '/'] = $url;
            }
            $ordered = [];
            foreach (array_keys($traffic) as $path) {
                if (isset($byPath[$path])) {
                    $ordered[] = $byPath[$path];
                    unset($byPath[$path]);
                }
            }
            $plan->urls = array_values(array_merge($ordered, $byPath));
            $plan->configureSelection('ga4_traffic_7d', count($plan->urls), count($plan->urls), false);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
