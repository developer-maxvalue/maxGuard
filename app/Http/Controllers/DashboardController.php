<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Models\Scan;
use App\Models\Website;
use App\Services\AiConfiguration;
use App\Support\UiText;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(AiConfiguration $aiConfiguration): View
    {
        $visibleWebsiteIds = fn () => Website::query()->accessibleBy(auth()->id())->select('id');
        $websites = Website::query()->accessibleBy(auth()->id())->orderBy('overall_score')->limit(8)->get();
        $totalWebsites = Website::query()->accessibleBy(auth()->id())->count();
        $critical = Finding::query()->whereIn('website_id', $visibleWebsiteIds())->open()->where('severity', 'critical')->count();
        $averageScore = (int) round((float) (Website::query()->accessibleBy(auth()->id())->avg('overall_score') ?? 100));
        $high = Finding::query()->whereIn('website_id', $visibleWebsiteIds())->open()->where('severity', 'high')->count();
        $health = [
            'total' => $totalWebsites,
            'healthy' => Website::query()->accessibleBy(auth()->id())->where('status', 'healthy')->count(),
            'review' => Website::query()->accessibleBy(auth()->id())->whereIn('status', ['review', 'high', 'pending', 'scanning'])->count(),
            'critical' => Website::query()->accessibleBy(auth()->id())->where('status', 'critical')->count(),
        ];
        $health['healthy_percent'] = $health['total'] > 0 ? round(($health['healthy'] / $health['total']) * 100, 2) : 0;
        $health['review_percent'] = $health['total'] > 0 ? round((($health['healthy'] + $health['review']) / $health['total']) * 100, 2) : 0;

        $trend = Scan::query()
            ->whereIn('website_id', $visibleWebsiteIds())
            ->where('status', Scan::STATUS_COMPLETED)
            ->whereNotNull('score')
            ->latest('finished_at')
            ->limit(16)
            ->pluck('score')
            ->reverse()
            ->values()
            ->all();

        return view('dashboard.index', [
            'metrics' => [
                ['label' => 'Tổng số website', 'value' => (string) $totalWebsites, 'note' => Website::query()->accessibleBy(auth()->id())->whereNotNull('last_scanned_at')->count().' đang được giám sát', 'tone' => 'primary', 'icon' => 'bi-globe2'],
                ['label' => 'Điểm tuân thủ', 'value' => (string) $averageScore, 'note' => 'Sức khỏe tổng hợp toàn hệ thống', 'tone' => $averageScore >= 85 ? 'success' : 'warning', 'icon' => 'bi-shield-check'],
                ['label' => 'Vấn đề nghiêm trọng', 'value' => (string) $critical, 'note' => $critical > 0 ? 'Cần khắc phục' : 'Không có vấn đề cản trở', 'tone' => $critical > 0 ? 'danger' : 'success', 'icon' => 'bi-exclamation-triangle'],
                ['label' => 'Vấn đề mức cao', 'value' => (string) $high, 'note' => $high > 0 ? 'Nên xử lý sớm' : 'Không có vấn đề mức cao', 'tone' => $high > 0 ? 'warning' : 'success', 'icon' => 'bi-shield-exclamation'],
            ],
            'sites' => $websites->map(fn (Website $website): array => $this->siteRow($website))->all(),
            'trend' => $trend === [] ? [100] : $trend,
            'health' => $health,
            'aiReady' => $aiConfiguration->anyReady(),
            'maxUrlSafetyLimit' => max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000)),
        ]);
    }

    private function siteRow(Website $website): array
    {
        $topFinding = $website->findings()->open()->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'review' then 3 else 4 end")->first();

        return [
            'slug' => $website->slug,
            'domain' => $website->domain,
            'score' => $website->overall_score,
            'status' => $website->status,
            'top_risk' => $topFinding ? UiText::text($topFinding->title) : 'Không có vấn đề cản trở',
            'findings' => $website->open_findings_count,
            'last_scan' => $website->last_scanned_at?->diffForHumans() ?? 'Chưa bao giờ',
        ];
    }
}
