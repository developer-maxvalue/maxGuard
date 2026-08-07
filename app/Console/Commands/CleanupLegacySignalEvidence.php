<?php

namespace App\Console\Commands;

use App\Models\EvidenceItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CleanupLegacySignalEvidence extends Command
{
    protected $signature = 'maxguard:cleanup-signal-evidence {--dry-run : Only report records and files that would be removed}';

    protected $description = 'Remove legacy detector-signal JSON files after confirming their signals exist in the findings table';

    public function handle(): int
    {
        $removed = 0;
        $skipped = 0;
        EvidenceItem::query()
            ->where('type', 'detector_signal')
            ->with('finding:id,signals')
            ->orderBy('id')
            ->chunkById(200, function ($items) use (&$removed, &$skipped): void {
                foreach ($items as $item) {
                    if ($item->finding === null || $item->finding->signals === null) {
                        $skipped++;
                        continue;
                    }
                    if (! $this->option('dry-run')) {
                        Storage::disk($item->disk)->delete($item->path);
                        $item->delete();
                    }
                    $removed++;
                }
            });

        $verb = $this->option('dry-run') ? 'Có thể xóa' : 'Đã xóa';
        $this->info("{$verb} {$removed} file/record signal JSON; bỏ qua {$skipped} record chưa có signals trong database.");

        return self::SUCCESS;
    }
}
