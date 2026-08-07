<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebsiteRequest;
use App\Models\Finding;
use App\Models\Scan;
use App\Models\Website;
use App\Services\AiConfiguration;
use App\Services\WebsiteAiReviewer;
use App\Services\CopyrightEvidenceExtractor;
use App\Services\UrlNormalizer;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
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
        ]);

        return redirect()->route('sites.show', $website)->with('status', 'Đã thêm website. Bạn có thể chạy lượt quét tuân thủ đầu tiên.');
    }

    public function show(Website $site, AiConfiguration $aiConfiguration, CopyrightEvidenceExtractor $copyrightEvidence): View
    {
        $this->authorizeOwner($site);
        $site->load([
            'ga4Connection',
            'findings' => fn ($query) => $query->open()->with('page.copyrightReviews'),
        ]);
        $latestScan = $site->scans()
            ->whereIn('status', [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL])
            ->latest('finished_at')
            ->first();
        $grouped = $site->findings->groupBy('category');
        $policyDefinitions = [
            'Nội dung cấm và lừa đảo' => ['Prohibited content', 'Deceptive practices'],
            'Bản quyền và trùng lặp' => ['Copyright', 'Duplicate content'],
            'Chất lượng nội dung' => ['Content quality', 'Technical trust'],
            'Trải nghiệm quảng cáo' => ['Ad experience'],
            'Quyền riêng tư và đồng ý' => ['Privacy & consent'],
        ];

        $policies = [];
        foreach ($policyDefinitions as $name => $categories) {
            // Eloquent\Collection::only() expects model primary keys. After
            // groupBy(), each item is itself a Collection, so calling only()
            // makes Eloquent call getKey() on a Collection and crashes.
            $findings = $site->findings->whereIn('category', $categories);
            $penalty = $findings->sum(fn (Finding $finding): int => match ($finding->severity) {
                'critical' => 30, 'high' => 18, 'review' => 8, default => 2,
            });
            $score = max(0, 100 - min(90, $penalty));
            $policies[] = [
                'name' => $name,
                'score' => $score,
                'count' => $findings->count().' phát hiện',
                'status' => Website::statusFromScore($score),
            ];
        }

        $riskUrls = $site->findings
            ->sortBy(fn (Finding $finding): int => match ($finding->severity) {
                'critical' => 1, 'high' => 2, 'review' => 3, default => 4
            })
            ->take(10)
            ->map(fn (Finding $finding): array => [
                'finding_id' => $finding->public_id,
                'path' => $finding->page ? (parse_url($finding->page->url, PHP_URL_PATH) ?: '/') : '/',
                'issue' => UiText::text($finding->title),
                'severity' => $finding->severity,
            ])->values()->all();

        $aiEvidenceExamples = $site->findings
            ->sortBy(fn (Finding $finding): int => match ($finding->severity) {
                'critical' => 1, 'high' => 2, 'review' => 3, default => 4
            })
            ->take(10)
            ->map(function (Finding $finding) use ($site, $copyrightEvidence): array {
                $signals = (array) ($finding->signals ?? []);

                return [
                    'finding_id' => $finding->public_id,
                    'url' => $finding->page?->url ?? $site->start_url,
                    'title' => UiText::text($finding->title),
                    'severity' => $finding->severity,
                    'confidence' => (int) $finding->confidence,
                    'quotes' => collect((array) ($signals['evidence'] ?? []))
                        ->merge((array) ($signals['matching_phrases'] ?? []))
                        ->filter(fn ($quote): bool => is_scalar($quote) && trim((string) $quote) !== '')
                        ->map(fn ($quote): string => trim((string) $quote))
                        ->take(3)->values()->all(),
                    'matched_url' => is_string($signals['matched_url'] ?? null) ? $signals['matched_url'] : null,
                    'similarity' => isset($signals['similarity']) ? (int) $signals['similarity'] : null,
                    'source_urls' => $copyrightEvidence->sourceUrls($finding),
                ];
            })->values()->all();

        return view('sites.show', [
            'site' => array_merge($this->row($site), [
                'policies' => $policies,
                'risky_urls' => $riskUrls,
            ]),
            'aiReady' => $aiConfiguration->isReady(),
            'maxUrlSafetyLimit' => max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000)),
            'ga4' => $site->ga4Connection,
            'trafficPages' => $site->pages()->where('ga4_views_7d', '>', 0)->orderByDesc('ga4_views_7d')->limit(20)->get(),
            'aiAssessment' => $latestScan?->ai_assessment,
            'aiAssessedAt' => $latestScan?->ai_assessed_at,
            'aiAssessmentScan' => $latestScan,
            'aiEvidenceExamples' => $aiEvidenceExamples,
        ]);
    }

    public function assess(Website $site, AiConfiguration $configuration, WebsiteAiReviewer $reviewer): RedirectResponse
    {
        $this->authorizeOwner($site);

        if (! $configuration->isReady()) {
            return back()->withErrors(['ai' => 'AI chưa được cấu hình. Hãy kiểm tra Cài đặt AI trước khi đánh giá.']);
        }

        $scan = $site->scans()
            ->whereIn('status', [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL])
            ->latest('finished_at')
            ->first();
        if ($scan === null) {
            return back()->withErrors(['ai' => 'Website cần có ít nhất một lượt quét hoàn tất trước khi AI đánh giá.']);
        }

        try {
            $reviewer->reviewAndStore($scan);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['ai' => 'Không thể hoàn tất đánh giá AI: '.mb_substr($exception->getMessage(), 0, 300)]);
        }

        return back()->with('status', 'AI đã đánh giá lại website theo dữ liệu của lượt quét gần nhất.');
    }

    public function destroy(Website $site): RedirectResponse
    {
        $this->authorizeOwner($site);

        if ($site->scans()->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])->exists()) {
            return back()->withErrors([
                'site' => 'Không thể xóa website khi có lượt quét đang chờ hoặc đang chạy.',
            ]);
        }

        $domain = $site->domain;

        DB::transaction(fn () => $site->delete());

        return redirect()->route('sites.index')->with(
            'status',
            "Đã xóa website {$domain} cùng các lượt quét, trang và phát hiện."
        );
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
            'top_risk' => $topFinding ? UiText::text($topFinding->title) : 'Không có vấn đề cản trở',
            'findings' => $website->open_findings_count,
            'last_scan' => $website->last_scanned_at?->diffForHumans() ?? 'Chưa bao giờ',
            'pages' => $scanned,
            'discovered_pages' => $discovered,
            'coverage' => $coverage,
            'coverage_partial' => (bool) $website->last_scan_partial || ($discovered > 0 && $scanned < $discovered),
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

    private function authorizeOwner(Website $website): void
    {
        abort_if(
            auth()->id() !== null
            && ! auth()->user()?->is_admin
            && $website->user_id !== auth()->id(),
            403
        );
    }

    private function exportCsv(Builder $query): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Tên', 'Tên miền', 'Trạng thái', 'Điểm', 'Trang đã lưu', 'Được phát hiện lần quét cuối', 'Đã quét lần cuối', 'Một phần', 'Phát hiện đang mở', 'Lần quét cuối']);

            $query->reorder('id')->chunkById(500, function ($websites) use ($output): void {
                foreach ($websites as $website) {
                    fputcsv($output, [
                        $website->name,
                        $website->domain,
                        UiText::label($website->status),
                        $website->overall_score,
                        $website->pages_count,
                        $website->last_discovered_pages,
                        $website->last_scanned_pages,
                        $website->last_scan_partial ? 'có' : 'không',
                        $website->open_findings_count,
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
