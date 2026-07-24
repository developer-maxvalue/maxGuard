<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebsiteRequest;
use App\Models\Finding;
use App\Models\Website;
use App\Services\UrlNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SiteController extends Controller
{
    public function index(): View|StreamedResponse
    {
        $query = Website::query()->accessibleBy(auth()->id());

        if (request()->filled('q')) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) request('q')).'%';
            $query->where(fn (Builder $query): Builder => $query
                ->where('name', 'like', $search)
                ->orWhere('domain', 'like', $search));
        }

        if (in_array(request('status'), ['pending', 'scanning', 'critical', 'high', 'review', 'healthy', 'disabled'], true)) {
            $query->where('status', request('status'));
        }

        if (request('export') === 'csv') {
            return $this->exportCsv($query);
        }

        $sites = $query->orderBy('overall_score')->paginate(25)->withQueryString();

        return view('sites.index', [
            'sites' => $sites->through(fn (Website $website): array => $this->row($website)),
        ]);
    }

    public function store(StoreWebsiteRequest $request, UrlNormalizer $urls): RedirectResponse
    {
        $data = $request->validated();
        $startUrl = $urls->normalize($data['start_url']);
        $domain = strtolower((string) parse_url($startUrl, PHP_URL_HOST));

        $website = Website::query()->create([
            'user_id' => auth()->id(),
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($domain),
            'domain' => $domain,
            'start_url' => $startUrl,
            'status' => 'pending',
            'expected_monthly_revenue' => $data['expected_monthly_revenue'] ?? 0,
        ]);

        return redirect()->route('sites.show', $website)->with('status', 'Website added. Run its first compliance scan when ready.');
    }

    public function show(Website $site): View
    {
        abort_if(auth()->id() !== null && $site->user_id !== auth()->id(), 403);
        $site->load(['findings' => fn ($query) => $query->open()->with('page')]);
        $grouped = $site->findings->groupBy('category');
        $policyDefinitions = [
            'Prohibited & deceptive' => ['Prohibited content', 'Deceptive practices'],
            'Copyright & duplicate' => ['Copyright', 'Duplicate content'],
            'Content quality' => ['Content quality', 'Technical trust'],
            'Ad experience' => ['Ad experience'],
            'Privacy & consent' => ['Privacy & consent'],
        ];

        $policies = [];
        foreach ($policyDefinitions as $name => $categories) {
            $findings = $grouped->only($categories)->flatten(1);
            $penalty = $findings->sum(fn (Finding $finding): int => match ($finding->severity) {
                'critical' => 30, 'high' => 18, 'review' => 8, default => 2,
            });
            $score = max(0, 100 - min(90, $penalty));
            $policies[] = [
                'name' => $name,
                'score' => $score,
                'count' => $findings->count().' findings',
                'status' => Website::statusFromScore($score),
            ];
        }

        $riskUrls = $site->findings
            ->sortBy(fn (Finding $finding): int => match ($finding->severity) {'critical' => 1, 'high' => 2, 'review' => 3, default => 4})
            ->take(10)
            ->map(fn (Finding $finding): array => [
                'finding_id' => $finding->public_id,
                'path' => $finding->page ? (parse_url($finding->page->url, PHP_URL_PATH) ?: '/') : '/',
                'issue' => $finding->title,
                'severity' => $finding->severity,
                'evidence' => $finding->evidenceItems()->count(),
            ])->values()->all();

        return view('sites.show', [
            'site' => array_merge($this->row($site), [
                'policies' => $policies,
                'risky_urls' => $riskUrls,
            ]),
            'aiReady' => (bool) config('maxguard.ai.enabled') && filled(config('maxguard.ai.api_key')),
            'maxUrlSafetyLimit' => max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000)),
        ]);
    }

    private function row(Website $website): array
    {
        $topFinding = $website->findings()->open()->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'review' then 3 else 4 end")->first();
        $discovered = (int) $website->last_discovered_pages;
        $scanned = (int) $website->last_scanned_pages;
        if ($discovered === 0 && $website->last_scanned_at !== null) {
            $discovered = (int) $website->pages_count;
            $scanned = (int) $website->pages_count;
        }
        $coverage = $discovered > 0 ? min(100, (int) round(($scanned / $discovered) * 100)) : 0;

        return [
            'slug' => $website->slug,
            'domain' => $website->domain,
            'score' => $website->overall_score,
            'status' => $website->status,
            'top_risk' => $topFinding?->title ?? 'No blocking issues',
            'findings' => $website->open_findings_count,
            'last_scan' => $website->last_scanned_at?->diffForHumans() ?? 'Never',
            'pages' => $scanned,
            'discovered_pages' => $discovered,
            'coverage' => $coverage,
            'coverage_partial' => (bool) $website->last_scan_partial || ($discovered > 0 && $scanned < $discovered),
            'revenue_risk' => '$'.number_format((float) $website->findings()->open()->sum('revenue_impact'), 0),
        ];
    }

    private function uniqueSlug(string $domain): string
    {
        $base = Str::slug(str_replace('.', '-', $domain));
        $slug = $base;
        $suffix = 2;
        while (Website::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function exportCsv(Builder $query): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Name', 'Domain', 'Status', 'Score', 'Stored pages', 'Discovered last scan', 'Scanned last scan', 'Partial', 'Open findings', 'Expected monthly revenue', 'Last scanned']);

            $query->reorder('id')->chunkById(500, function ($websites) use ($output): void {
                foreach ($websites as $website) {
                    fputcsv($output, [
                        $website->name,
                        $website->domain,
                        $website->status,
                        $website->overall_score,
                        $website->pages_count,
                        $website->last_discovered_pages,
                        $website->last_scanned_pages,
                        $website->last_scan_partial ? 'yes' : 'no',
                        $website->open_findings_count,
                        $website->expected_monthly_revenue,
                        $website->last_scanned_at?->toIso8601String(),
                    ]);
                }
            });

            fclose($output);
        }, 'maxguard-sites-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
