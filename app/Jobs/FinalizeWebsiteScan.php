<?php

namespace App\Jobs;

use App\Services\ScanRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FinalizeWebsiteScan implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;
    public int $tries = 2160;
    public int $uniqueFor = 21_600;

    /** @var list<int> */
    public array $backoff = [5, 10, 20, 30];

    public function __construct(public int $scanId)
    {
        $this->timeout = max(120, (int) config('maxguard.finalize_timeout_seconds', 900));
    }

    public function uniqueId(): string
    {
        return 'maxguard-finalize-'.$this->scanId;
    }

    public function handle(ScanRunner $runner): void
    {
        if (! $runner->finalizeParallel($this->scanId)) {
            $this->release(10);
        }
    }
}
