<?php

namespace App\Services;

use App\Jobs\RunWebsiteScan;
use App\Models\Scan;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ScanDispatcher
{
    /**
     * Create exactly one scan and enqueue its orchestrator.
     *
     * The website row is locked so two browser requests cannot start duplicate
     * scans. forceRescan=false lets ScanRunner reuse a compatible analysis when
     * the URL content_hash is unchanged; true is intended for ruleset/key tests.
     */
    public function dispatch(
        Website $website,
        string $type = 'full',
        ?int $requestedBy = null,
        ?int $maxUrls = null,
        bool $useAi = false,
        bool $forceRescan = false,
    ): Scan {
        $aiConfiguration = app(AiConfiguration::class);
        if (! in_array($type, ['full', 'priority', 'copyright', 'ads', 'privacy'], true)) {
            throw ValidationException::withMessages(['scan_type' => 'Unsupported scan type.']);
        }

        $safetyLimit = max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000));
        if ($maxUrls !== null && ($maxUrls < 1 || $maxUrls > $safetyLimit)) {
            throw ValidationException::withMessages(['max_urls' => "Maximum newest posts must be between 1 and {$safetyLimit}."]);
        }
        if ($useAi && ! $aiConfiguration->isReady()) {
            throw ValidationException::withMessages([
                'use_ai' => 'AI chưa được cấu hình. Hãy mở Quản trị → Cài đặt AI để thiết lập kết nối.',
            ]);
        }

        $previousStatus = $website->status;
        $scan = DB::transaction(function () use ($website, $type, $requestedBy, $maxUrls, $useAi, $forceRescan, &$previousStatus): Scan {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            $previousStatus = $locked->status;
            if ($locked->status === 'disabled') {
                throw ValidationException::withMessages(['site' => 'This website is disabled.']);
            }
            $running = $locked->scans()->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])->exists();
            if ($running) {
                throw ValidationException::withMessages(['site' => 'This website already has a queued or running scan.']);
            }

            $scan = $locked->scans()->create([
                'requested_by' => $requestedBy,
                'type' => $type,
                'status' => Scan::STATUS_QUEUED,
                'max_urls' => $maxUrls,
                'use_ai' => $useAi,
                'force_rescan' => $forceRescan,
                'ruleset_version' => '1.1.1',
            ]);

            $locked->update(['status' => 'scanning']);

            return $scan;
        });

        try {
            RunWebsiteScan::dispatch($scan)
                ->onQueue((string) config('maxguard.queue', 'scans'))
                ->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
            $scan->update([
                'status' => Scan::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
            ]);
            $website->update(['status' => $previousStatus]);

            throw ValidationException::withMessages([
                'queue' => 'The scan could not be added to the queue. Run [php artisan maxguard:queue-doctor] and check the queue worker.',
            ]);
        }

        return $scan;
    }
}
