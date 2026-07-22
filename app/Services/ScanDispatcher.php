<?php

namespace App\Services;

use App\Jobs\RunWebsiteScan;
use App\Models\Scan;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ScanDispatcher
{
    public function dispatch(Website $website, string $type = 'full', ?int $requestedBy = null): Scan
    {
        if (! in_array($type, ['full', 'priority', 'copyright', 'ads', 'privacy'], true)) {
            throw ValidationException::withMessages(['scan_type' => 'Unsupported scan type.']);
        }

        $scan = DB::transaction(function () use ($website, $type, $requestedBy): Scan {
            $locked = Website::query()->lockForUpdate()->findOrFail($website->id);
            if ($locked->status === 'disabled') {
                throw ValidationException::withMessages(['site' => 'This website is disabled.']);
            }
            if ((bool) config('maxguard.require_ownership_verification', true) && $locked->ownership_verified_at === null) {
                throw ValidationException::withMessages(['site' => 'Verify website ownership before running a scan.']);
            }
            $running = $locked->scans()->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])->exists();
            if ($running) {
                throw ValidationException::withMessages(['site' => 'This website already has a queued or running scan.']);
            }

            $scan = $locked->scans()->create([
                'requested_by' => $requestedBy,
                'type' => $type,
                'status' => Scan::STATUS_QUEUED,
                'ruleset_version' => '1.0.0',
            ]);

            $locked->update(['status' => 'scanning']);

            return $scan;
        });

        RunWebsiteScan::dispatch($scan)
            ->onQueue((string) config('maxguard.queue', 'scans'))
            ->afterCommit();

        return $scan;
    }
}
