<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Models\Website;
use App\Services\ScanRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RunWebsiteScan implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public Scan $scan)
    {
    }

    public function uniqueId(): string
    {
        return 'maxguard-scan-'.$this->scan->getKey();
    }

    public function handle(ScanRunner $runner): void
    {
        $runner->run($this->scan);
    }

    public function failed(Throwable $exception): void
    {
        $this->scan->refresh();
        if ($this->scan->status !== Scan::STATUS_COMPLETED) {
            $this->scan->update([
                'status' => Scan::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 5000),
            ]);

            $this->scan->website?->update([
                'status' => $this->scan->website->last_scanned_at ? Website::statusFromScore($this->scan->website->overall_score) : 'pending',
            ]);
        }
    }
}
