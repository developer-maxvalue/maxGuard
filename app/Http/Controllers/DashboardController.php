<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Models\Scan;
use App\Models\Website;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $websiteIds = Website::query()->accessibleBy(auth()->id())->pluck('id');
        $websites = Website::query()->whereIn('id', $websiteIds)->orderBy('overall_score')->limit(8)->get();
        $critical = Finding::query()->whereIn('website_id', $websiteIds)->open()->where('severity', 'critical')->count();
        $averageScore = (int) round((float) (Website::query()->whereIn('id', $websiteIds)->avg('overall_score') ?? 100));
        $protectedRevenue = Website::query()->whereIn('id', $websiteIds)->where('overall_score', '>=', 80)->sum('expected_monthly_revenue');
        $health = [
            'total' => $websiteIds->count(),
            'healthy' => Website::query()->whereIn('id', $websiteIds)->where('status', 'healthy')->count(),
            'review' => Website::query()->whereIn('id', $websiteIds)->whereIn('status', ['review', 'high', 'pending', 'scanning'])->count(),
            'critical' => Website::query()->whereIn('id', $websiteIds)->where('status', 'critical')->count(),
        ];
        $health['healthy_percent'] = $health['total'] > 0 ? round(($health['healthy'] / $health['total']) * 100, 2) : 0;
        $health['review_percent'] = $health['total'] > 0 ? round((($health['healthy'] + $health['review']) / $health['total']) * 100, 2) : 0;

        $trend = Scan::query()
            ->whereIn('website_id', $websiteIds)
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
                ['label' => 'Total sites', 'value' => (string) $websiteIds->count(), 'note' => Website::query()->whereIn('id', $websiteIds)->whereNotNull('last_scanned_at')->count().' monitored', 'tone' => 'primary', 'icon' => 'bi-globe2'],
                ['label' => 'Compliance score', 'value' => (string) $averageScore, 'note' => 'Portfolio weighted health', 'tone' => $averageScore >= 85 ? 'success' : 'warning', 'icon' => 'bi-shield-check'],
                ['label' => 'Critical issues', 'value' => (string) $critical, 'note' => $critical > 0 ? 'Requires remediation' : 'No blocking issues', 'tone' => $critical > 0 ? 'danger' : 'success', 'icon' => 'bi-exclamation-triangle'],
                ['label' => 'Protected revenue', 'value' => '$'.number_format((float) $protectedRevenue / 1000, 1).'K', 'note' => 'estimated monthly', 'tone' => 'info', 'icon' => 'bi-currency-dollar'],
            ],
            'sites' => $websites->map(fn (Website $website): array => $this->siteRow($website))->all(),
            'trend' => $trend === [] ? [100] : $trend,
            'health' => $health,
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
            'top_risk' => $topFinding?->title ?? 'No blocking issues',
            'findings' => $website->open_findings_count,
            'last_scan' => $website->last_scanned_at?->diffForHumans() ?? 'Never',
        ];
    }
}
