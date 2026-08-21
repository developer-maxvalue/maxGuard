<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartScanRequest;
use App\Models\Finding;
use App\Models\Scan;
use App\Models\ScanTarget;
use App\Models\Website;
use App\Services\AiConfiguration;
use App\Services\ScanDispatcher;
use App\Support\UiText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ScanController extends Controller
{
    /**
     * Render the scan center with queue capacity and recent portfolio activity.
     */
    public function index(AiConfiguration $aiConfiguration): View
    {
        $visibleScans = Scan::query()->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()));
        $running = (clone $visibleScans)->where('status', Scan::STATUS_RUNNING)->count();
        $queued = (clone $visibleScans)->where('status', Scan::STATUS_QUEUED)->count();
        $targetQuery = ScanTarget::query()->whereHas(
            'scan.website',
            fn ($query) => $query->accessibleBy(auth()->id())
        );
        $runningTargets = (clone $targetQuery)->where('status', ScanTarget::STATUS_RUNNING)->count();
        $queuedTargets = (clone $targetQuery)->where('status', ScanTarget::STATUS_QUEUED)->count();
        $pageWorkers = max(1, (int) config('maxguard.recommended_page_workers', 6));
        $batchSize = max(1, min(100, (int) config('maxguard.page_batch_size', 10)));
        $connection = (string) config('queue.default', 'sync');
        $controlTimeout = max(
            120,
            (int) config('maxguard.orchestrator_timeout_seconds', 900),
            (int) config('maxguard.finalize_timeout_seconds', 900),
        );
        $pageTimeout = max(120, (int) config('maxguard.page_job_timeout_seconds', 1800));

        return view('scans.index', [
            'sites' => Website::query()->accessibleBy(auth()->id())->orderBy('domain')->get()->map(fn (Website $website): array => [
                'domain' => $website->domain,
                'slug' => $website->slug,
            ])->all(),
            'recentScans' => $this->recentScans(),
            'liveFindings' => $this->liveFindings(),
            'scanStats' => [
                'running' => $running,
                'queued' => $queued,
                'target_running' => $runningTargets,
                'target_queued' => $queuedTargets,
                'page_workers' => $pageWorkers,
                'batch_size' => $batchSize,
                'utilization' => min(100, (int) round(($runningTargets / max(1, $pageWorkers * $batchSize)) * 100)),
            ],
            'aiInfo' => [
                'ready' => $aiConfiguration->isReady(),
                'model' => (string) config('maxguard.ai.model', 'gpt-5.6-terra'),
                'page_limit' => (int) config('maxguard.ai.max_pages_per_scan', 100),
            ],
            'maxUrlSafetyLimit' => max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000)),
            'queueInfo' => [
                'connection' => $connection,
                'driver' => (string) config('queue.connections.'.config('queue.default', 'sync').'.driver', ''),
                'control_queues' => implode(',', [
                    (string) config('maxguard.queue', 'scans'),
                    (string) config('maxguard.finalize_queue', 'scan-finalize'),
                ]),
                'page_queue' => (string) config('maxguard.page_queue', 'scan-pages'),
                'control_worker_command' => sprintf(
                    'php artisan queue:work %s --queue=%s,%s --sleep=2 --tries=3 --timeout=%d --memory=%d',
                    $connection,
                    (string) config('maxguard.queue', 'scans'),
                    (string) config('maxguard.finalize_queue', 'scan-finalize'),
                    $controlTimeout,
                    max(128, (int) config('maxguard.worker_memory_mb', 1024)),
                ),
                'page_worker_command' => sprintf(
                    'php artisan queue:work %s --queue=%s --sleep=1 --tries=2 --timeout=%d --memory=%d',
                    $connection,
                    (string) config('maxguard.page_queue', 'scan-pages'),
                    $pageTimeout,
                    max(128, (int) config('maxguard.worker_memory_mb', 1024)),
                ),
                'page_workers' => $pageWorkers,
            ],
        ]);
    }

    /**
     * Show every discovered URL and its live processing stage for one scan.
     */
    public function show(Scan $scan): View
    {
        $this->authorizeScan($scan);
        $scan->load('website')->loadCount([
            'targets as queued_targets_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_QUEUED),
            'targets as running_targets_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_RUNNING),
            'targets as failed_targets_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_FAILED),
            'targets as reused_targets_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_REUSED),
        ]);
        $targets = $scan->targets()
            ->with('page')
            ->withCount('events')
            ->orderBy('position')
            ->paginate(100);

        return view('scans.show', compact('scan', 'targets'));
    }

    /**
     * Show the complete sanitized event timeline and findings for one URL.
     */
    public function target(Scan $scan, ScanTarget $target): View
    {
        $this->authorizeScan($scan);
        abort_unless($target->scan_id === $scan->id, 404);
        $target->load([
            'page.findings' => fn ($query) => $query->where('scan_id', $scan->id)->latest(),
            'events',
        ]);

        return view('scans.target', compact('scan', 'target'));
    }

    /**
     * Return lightweight live URL state for polling without reloading the page.
     */
    public function targetsLive(Scan $scan): JsonResponse
    {
        $this->authorizeScan($scan);

        return response()->json([
            'scan' => [
                'status' => $scan->status,
                'progress' => $scan->progress,
                'pages_scanned' => $scan->pages_scanned,
                'pages_discovered' => $scan->pages_discovered,
                'current_url' => $scan->current_url,
            ],
            'targets' => $scan->targets()->orderBy('position')->get()->map(fn (ScanTarget $target): array => [
                'id' => $target->id,
                'status' => $target->status,
                'stage' => $target->current_stage,
                'attempts' => $target->attempts,
                'findings' => $target->findings_count,
                'error' => $target->error_message,
                'updated_at' => $target->updated_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(StartScanRequest $request, ScanDispatcher $dispatcher): RedirectResponse
    {
        $data = $request->validated();
        $query = Website::query()->accessibleBy(auth()->id());
        if ($data['site'] !== 'all-sites') {
            $query->where(fn ($query) => $query->where('domain', $data['site'])->orWhere('slug', $data['site']));
        }

        $websites = $query->get();
        if ($websites->isEmpty()) {
            return back()->withErrors(['site' => 'Không tìm thấy website.'])->withInput();
        }

        $queued = 0;
        $skipped = [];
        foreach ($websites as $website) {
            try {
                $dispatcher->dispatch(
                    $website,
                    $data['scan_type'],
                    auth()->id(),
                    (bool) ($data['scan_all_site'] ?? false) ? null : (int) ($data['max_urls'] ?? 100),
                    (bool) ($data['use_ai'] ?? false),
                    (bool) ($data['force_rescan'] ?? false),
                );
                $queued++;
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $skipped = array_merge($skipped, $exception->validator->errors()->all());
            }
        }

        if ($queued === 0) {
            return back()->withErrors([
                'queue' => $skipped[0] ?? 'Không có lượt quét nào được đưa vào hàng đợi. Chạy [php artisan maxguard:queue-doctor] để kiểm tra cấu hình hàng đợi.',
            ])->withInput();
        }

        $response = $websites->count() === 1
            ? redirect()->route('sites.show', $websites->first())
            : redirect()->route('sites.index');
        $response->with('status', "Đã đưa {$queued} lượt quét vào hàng đợi.");

        if ($skipped !== []) {
            $response->with('error', 'Đã bỏ qua '.count($skipped).' lượt quét: '.$skipped[0]);
        }

        return $response;
    }

    /** Abort when the current user does not own the scan's website. */
    private function authorizeScan(Scan $scan): void
    {
        $scan->loadMissing('website');
        abort_if(
            auth()->id() !== null
            && ! auth()->user()?->is_admin
            && $scan->website->user_id !== auth()->id(),
            403
        );
    }

    public function live(): JsonResponse
    {
        return response()->json([
            'scans' => $this->recentScans()->map(fn (Scan $scan): array => $this->scanPayload($scan))->all(),
            'findings' => $this->liveFindings()->map(fn (Finding $finding): array => $this->findingPayload($finding))->all(),
            'refreshed_at' => now()->toIso8601String(),
        ]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Scan> */
    private function recentScans()
    {
        return Scan::query()
            ->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()))
            ->with('website')
            ->withCount([
                'findings',
                'targets as targets_queued_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_QUEUED),
                'targets as targets_running_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_RUNNING),
                'targets as targets_failed_count' => fn ($query) => $query->where('status', ScanTarget::STATUS_FAILED),
            ])
            ->latest()
            ->limit(10)
            ->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Finding> */
    private function liveFindings()
    {
        return Finding::query()
            ->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()))
            ->with(['website', 'page', 'scan'])
            ->latest('last_seen_at')
            ->limit(100)
            ->get();
    }

    /** @return array<string, mixed> */
    private function scanPayload(Scan $scan): array
    {
        return [
            'id' => $scan->id,
            'detail_url' => route('scans.show', $scan),
            'website' => $scan->website->domain,
            'type' => match ($scan->type) {
                'full' => 'Toàn diện',
                'priority' => 'Ưu tiên',
                'copyright' => 'Bản quyền',
                'ads' => 'Quảng cáo',
                'privacy' => 'Quyền riêng tư',
                default => $scan->type,
            },
            'status' => $scan->status,
            'progress' => $scan->progress,
            'pages_scanned' => $scan->pages_scanned,
            'pages_skipped_unchanged' => $scan->pages_skipped_unchanged,
            'pages_discovered' => $scan->pages_discovered,
            'max_urls' => $scan->max_urls,
            'findings_count' => $scan->findings_count,
            'ai_enabled' => (bool) $scan->use_ai,
            'force_rescan' => (bool) $scan->force_rescan,
            'ai_pages_analyzed' => $scan->ai_pages_analyzed,
            'ai_findings_count' => $scan->ai_findings_count,
            'ai_limit_reached' => (bool) data_get($scan->meta, 'ai_limit_reached', false),
            'ai_errors' => (int) data_get($scan->meta, 'ai_errors', 0),
            'is_sampled' => (bool) data_get($scan->meta, 'is_sampled', false),
            'sampling_mode' => (string) data_get($scan->meta, 'sampling_mode', 'all_urls'),
            'available_urls' => (int) data_get($scan->meta, 'available_urls', $scan->pages_discovered),
            'site_urls_discovered' => (int) data_get($scan->meta, 'site_urls_discovered', $scan->pages_discovered),
            'current_url' => $scan->current_url,
            'started' => ($scan->started_at ?? $scan->created_at)->diffForHumans(),
            'error_message' => $scan->error_message,
            'sitemaps' => (int) data_get($scan->meta, 'sitemap_files_processed', 0),
            'failed' => (int) data_get($scan->meta, 'failed_requests', 0),
            'blocked' => (int) data_get($scan->meta, 'blocked_by_robots', 0),
            'parallel_scan' => (bool) data_get($scan->meta, 'parallel_scan', false),
            'batch_size' => (int) data_get($scan->meta, 'page_batch_size', 0),
            'batches_completed' => (int) data_get($scan->meta, 'batches_completed', 0),
            'batches_total' => (int) data_get($scan->meta, 'batches_total', 0),
            'targets_queued' => (int) ($scan->targets_queued_count ?? data_get($scan->meta, 'targets_queued', 0)),
            'targets_running' => (int) ($scan->targets_running_count ?? data_get($scan->meta, 'targets_running', 0)),
            'targets_failed' => (int) ($scan->targets_failed_count ?? data_get($scan->meta, 'targets_failed', 0)),
        ];
    }

    /** @return array<string, mixed> */
    private function findingPayload(Finding $finding): array
    {
        return [
            'id' => $finding->public_id,
            'scan_id' => $finding->scan_id,
            'website' => $finding->website->domain,
            'url' => $finding->page?->url ?? $finding->website->start_url,
            'title' => UiText::text($finding->title),
            'category' => UiText::label($finding->category),
            'severity' => $finding->severity,
            'confidence' => $finding->confidence,
            'status' => $finding->status,
            'source' => str_starts_with($finding->rule_key, 'ai.') ? 'AI' : 'Quy tắc',
            'detected' => $finding->last_seen_at->diffForHumans(),
            'detail_url' => route('findings.show', $finding),
        ];
    }
}
