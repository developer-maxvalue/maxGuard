<?php

namespace App\Jobs;

use App\Models\ScanTarget;
use App\Services\ScanRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

final class RunScanPageBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;
    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param list<int> $targetIds */
    public function __construct(
        public int $scanId,
        public array $targetIds,
        public ?string $claimToken = null,
    ) {
        $this->claimToken ??= (string) Str::uuid();
        $this->timeout = max(120, (int) config('maxguard.page_job_timeout_seconds', 1800));
    }

    public function handle(ScanRunner $runner): void
    {
        $runner->runParallelBatch($this->scanId, $this->targetIds, (string) $this->claimToken);
    }

    public function failed(Throwable $exception): void
    {
        ScanTarget::query()
            ->where('scan_id', $this->scanId)
            ->whereIn('id', $this->targetIds)
            ->where(function ($query): void {
                $query->where('status', ScanTarget::STATUS_QUEUED)
                    ->orWhere(function ($query): void {
                        $query->where('status', ScanTarget::STATUS_RUNNING)
                            ->where('claim_token', $this->claimToken);
                    });
            })
            ->update([
                'status' => ScanTarget::STATUS_FAILED,
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
                'finished_at' => now(),
            ]);

        FinalizeWebsiteScan::dispatch($this->scanId)
            ->onQueue((string) config('maxguard.finalize_queue', 'scan-finalize'));
    }
}
