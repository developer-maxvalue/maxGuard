<?php

namespace App\Console\Commands;

use App\Models\Scan;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecoverStuckScans extends Command
{
    protected $signature = 'maxguard:recover-stuck-scans
        {--older-than=30 : Cancel queued/running scans with no update for this many minutes}';

    protected $description = 'Cancel stale MaxGuard scans so the website can be queued again safely';

    public function handle(): int
    {
        $minutes = filter_var($this->option('older-than'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($minutes === false) {
            $this->error('--older-than must be an integer of at least 1 minute.');

            return self::INVALID;
        }

        $cutoff = now()->subMinutes($minutes);
        $cancelled = 0;

        Scan::query()
            ->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($scans) use (&$cancelled): void {
                foreach ($scans as $scan) {
                    DB::transaction(function () use ($scan, &$cancelled): void {
                        $locked = Scan::query()->lockForUpdate()->find($scan->id);
                        if ($locked === null || ! in_array($locked->status, [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING], true)) {
                            return;
                        }

                        $locked->update([
                            'status' => Scan::STATUS_CANCELLED,
                            'finished_at' => now(),
                            'error_message' => 'Cancelled by maxguard:recover-stuck-scans because the queue worker did not update this scan.',
                        ]);

                        $website = $locked->website;
                        if ($website !== null && ! $website->scans()->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])->exists()) {
                            $website->update([
                                'status' => $website->last_scanned_at
                                    ? Website::statusFromScore($website->overall_score)
                                    : 'pending',
                            ]);
                        }

                        $cancelled++;
                    });
                }
            });

        $this->info("Cancelled {$cancelled} stale scan(s). Jobs that arrive later will detect the cancelled state and exit without crawling.");

        return self::SUCCESS;
    }
}
