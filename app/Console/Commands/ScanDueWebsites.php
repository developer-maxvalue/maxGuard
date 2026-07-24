<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\ScanDispatcher;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class ScanDueWebsites extends Command
{
    protected $signature = 'maxguard:scan-due-sites
        {--site= : Domain or slug to scan}
        {--type=full : full, priority, copyright, ads or privacy}
        {--max-urls= : Maximum newest posts per website when sitemap lastmod is available}
        {--ai : Enable configured AI policy analysis}
        {--force : Re-analyze unchanged pages}';

    protected $description = 'Queue compliance scans for websites that are due';

    public function handle(ScanDispatcher $dispatcher): int
    {
        $type = (string) $this->option('type');
        $maxUrls = $this->option('max-urls') !== null ? (int) $this->option('max-urls') : null;
        $useAi = (bool) $this->option('ai');
        $forceRescan = (bool) $this->option('force');
        if (! in_array($type, ['full', 'priority', 'copyright', 'ads', 'privacy'], true)) {
            $this->error('Unsupported scan type.');

            return self::INVALID;
        }

        $query = Website::query()->dueForScan();
        if ($site = $this->option('site')) {
            $query->where(fn ($query) => $query->where('domain', $site)->orWhere('slug', $site));
        }

        $queued = 0;
        $query->orderBy('id')->chunkById(100, function ($websites) use ($dispatcher, $type, $maxUrls, $useAi, $forceRescan, &$queued): void {
            foreach ($websites as $website) {
                try {
                    $dispatcher->dispatch($website, $type, null, $maxUrls, $useAi, $forceRescan);
                    $queued++;
                } catch (ValidationException) {
                    $this->warn("Skipped {$website->domain}: a scan is already active.");
                }
            }
        });

        $this->info("Queued {$queued} scan(s).");

        return self::SUCCESS;
    }
}
