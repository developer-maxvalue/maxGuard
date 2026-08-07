<?php

namespace App\Http\Controllers;

use App\Models\Finding;
use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use Illuminate\View\View;

final class AdminController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.index', [
            'metrics' => [
                'users' => User::query()->count(),
                'sites' => Website::query()->count(),
                'running_scans' => Scan::query()->whereIn('status', [
                    Scan::STATUS_QUEUED,
                    Scan::STATUS_RUNNING,
                ])->count(),
                'open_findings' => Finding::query()->open()->count(),
                'critical_findings' => Finding::query()->open()->where('severity', 'critical')->count(),
                'sites_reviewed_by_ai' => Scan::query()->whereNotNull('ai_assessed_at')->distinct('website_id')->count('website_id'),
            ],
            'users' => User::query()
                ->withCount('websites')
                ->latest()
                ->limit(20)
                ->get(),
            'sites' => Website::query()
                ->with('owner')
                ->orderBy('overall_score')
                ->limit(20)
                ->get(),
            'scans' => Scan::query()
                ->with(['website.owner'])
                ->latest()
                ->limit(15)
                ->get(),
            'findings' => Finding::query()
                ->with(['website.owner'])
                ->open()
                ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'review' then 3 else 4 end")
                ->latest('last_seen_at')
                ->limit(15)
                ->get(),
        ]);
    }
}
