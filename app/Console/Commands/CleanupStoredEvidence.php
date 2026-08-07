<?php

namespace App\Console\Commands;

use App\Models\EvidenceItem;
use App\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CleanupStoredEvidence extends Command
{
    protected $signature = 'maxguard:cleanup-stored-evidence {--dry-run : Only report files and records that would be removed}';

    protected $description = 'Remove stored evidence files and database records after file evidence storage has been disabled';

    public function handle(): int
    {
        $prefix = trim((string) config('maxguard.evidence_prefix', 'maxguard/evidence'), '/').'/';
        $files = EvidenceItem::query()
            ->get(['disk', 'path'])
            ->filter(fn (EvidenceItem $item): bool => str_starts_with(ltrim($item->path, '/'), $prefix))
            ->unique(fn (EvidenceItem $item): string => $item->disk.'|'.$item->path)
            ->values();
        $records = EvidenceItem::query()->count();
        $pagePaths = Page::query()->whereNotNull('snapshot_path')->count();

        if (! $this->option('dry-run')) {
            foreach ($files as $file) {
                Storage::disk($file->disk)->delete($file->path);
            }
            EvidenceItem::query()->delete();
            Page::query()->whereNotNull('snapshot_path')->update(['snapshot_path' => null]);
        }

        $verb = $this->option('dry-run') ? 'Sẽ xóa' : 'Đã xóa';
        $this->info("{$verb} {$files->count()} file, {$records} evidence record và xóa đường dẫn snapshot khỏi {$pagePaths} page.");

        return self::SUCCESS;
    }
}
