<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWebsiteRequest;
use App\Models\Scan;
use App\Models\Website;
use App\Services\AiConfiguration;
use App\Services\ScanDispatcher;
use App\Services\UrlNormalizer;
use App\Services\WebsiteAiAssessmentDispatcher;
use App\Support\GooglePolicyReference;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

        $sites = $query->orderBy('id', 'DESC')->paginate(25)->withQueryString();

        return view('sites.index', [
            'sites' => $sites->through(fn (Website $website): array => $this->row($website)),
        ]);
    }

    public function store(StoreWebsiteRequest $request, UrlNormalizer $urls, ScanDispatcher $dispatcher): RedirectResponse
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

        if (! (bool) ($data['start_scan'] ?? false)) {
            return redirect()->route('sites.show', $website)->with('status', 'Đã thêm website.');
        }

        try {
            $dispatcher->dispatch(
                $website,
                'full',
                auth()->id(),
                (bool) ($data['scan_all_site'] ?? false) ? null : (int) ($data['max_urls'] ?? 100),
                false,
                false,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            return redirect()->route('sites.show', $website)
                ->withErrors($exception->errors())
                ->with('status', 'Đã thêm website nhưng chưa thể đưa lượt quét vào hàng đợi.');
        }

        return redirect()->route('sites.show', $website)
            ->with('status', 'Đã thêm website và đưa lượt quét đầu tiên vào hàng đợi.');
    }

    public function show(Website $site, AiConfiguration $aiConfiguration): View
    {
        $this->authorizeOwner($site);
        $site->load('ga4Connection');
        $latestScan = $site->scans()
            ->whereIn('status', [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL])
            ->latest('finished_at')
            ->first();
        $activeScan = $site->scans()
            ->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])
            ->latest()
            ->first();
        $findingSummary = $site->findings()
            ->open()
            ->selectRaw('category, severity, count(*) as aggregate')
            ->groupBy('category', 'severity')
            ->get();
        $policyDefinitions = [
            'Nội dung cấm và lừa đảo' => ['Prohibited content', 'Deceptive practices'],
            'Bản quyền và trùng lặp' => ['Copyright', 'Duplicate content'],
            'Chất lượng nội dung' => ['Content quality', 'Technical trust', 'Publisher requirements'],
            'Trải nghiệm quảng cáo' => ['Ad experience'],
            'Quyền riêng tư và đồng ý' => ['Privacy & consent'],
        ];
        $totalUrls = max(0, (int) ($latestScan?->pages_scanned ?: $site->last_scanned_pages ?: $site->pages_count));

        $policies = [];
        foreach ($policyDefinitions as $name => $categories) {
            $findings = $findingSummary->whereIn('category', $categories);
            $affectedUrls = $site->findings()
                ->open()
                ->whereIn('category', $categories)
                ->selectRaw('count(distinct coalesce(page_id, 0)) as aggregate')
                ->first()?->aggregate;
            $worstSeverity = collect(['critical', 'high', 'review', 'info'])->first(
                fn (string $severity): bool => $findings->contains('severity', $severity)
            );
            $policies[] = [
                'name' => $name,
                'violating_urls' => (int) $affectedUrls,
                'total_urls' => $totalUrls,
                'status' => $worstSeverity ?? 'healthy',
            ];
        }

        $findingQuery = $site->findings()->open()->with('page');
        if (in_array(request('finding_severity'), ['critical', 'high', 'review', 'info'], true)) {
            $findingQuery->where('severity', request('finding_severity'));
        }
        if (request()->filled('finding_category')) {
            $findingQuery->where('category', (string) request('finding_category'));
        }
        $findingReport = $findingQuery
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'review' then 3 else 4 end")
            ->orderByDesc('confidence')
            ->paginate(25, ['*'], 'findings_page')
            ->withQueryString();
        $findingCategories = UiText::findingCategories();
        $aiAssessment = $latestScan?->ai_assessment;
        $policySection = static fn (string $url): string => match (true) {
            str_contains($url, '/11190248'), str_contains($url, '/81904') => 'content_overview',
            str_contains($url, '/11185755') => 'transparency_overview',
            str_contains($url, '/1348695') => 'adsense_requirements_overview',
            default => 'policy_overview',
        };
        $aiPolicyReferences = collect((array) data_get($aiAssessment, 'policy_references', []))
            ->filter(fn ($reference): bool => is_array($reference) && filter_var($reference['policy_url'] ?? null, FILTER_VALIDATE_URL) !== false)
            ->map(function (array $reference) use ($policySection): array {
                $reference['section'] = in_array($reference['section'] ?? null, ['content_overview', 'transparency_overview', 'adsense_requirements_overview', 'policy_overview'], true)
                    ? $reference['section']
                    : $policySection((string) $reference['policy_url']);

                return $reference;
            })
            ->values();
        if ($aiPolicyReferences->isEmpty() && $aiAssessment) {
            $aiPolicyReferences = $findingSummary->pluck('category')->unique()->map(fn (string $category): array => [
                'section' => match ($category) {
                    'Copyright', 'Duplicate content', 'Content quality' => 'content_overview',
                    'Deceptive practices', 'Publisher requirements' => 'transparency_overview',
                    'Privacy & consent' => 'adsense_requirements_overview',
                    default => 'policy_overview',
                },
                'issue' => UiText::label($category),
                'relevance' => 'Tài liệu Google liên quan đến nhóm vấn đề được phát hiện trong dữ liệu quét.',
                'policy_url' => GooglePolicyReference::url($category),
                'policy_title' => GooglePolicyReference::title($category),
            ])->values();
        }

        return view('sites.show', [
            'site' => array_merge($this->row($site), [
                'policies' => $policies,
            ]),
            'aiReady' => $aiConfiguration->isReady() || is_array(data_get($latestScan?->meta, 'web_review')),
            'maxUrlSafetyLimit' => max(1, (int) config('maxguard.crawler.max_discovered_urls', 100_000)),
            'ga4' => $site->ga4Connection,
            'trafficPages' => $site->pages()->where('ga4_views_7d', '>', 0)->orderByDesc('ga4_views_7d')->limit(20)->get(),
            'aiAssessment' => $aiAssessment,
            'aiPolicyReferences' => $aiPolicyReferences,
            'aiAssessedAt' => $latestScan?->ai_assessed_at,
            'aiAssessmentScan' => $latestScan,
            'aiAssessmentStatus' => (string) data_get($latestScan?->meta, 'ai_assessment_status', $latestScan?->ai_assessment ? 'completed' : ''),
            'aiAssessmentError' => (string) data_get($latestScan?->meta, 'ai_assessment_error', ''),
            'findingReport' => $findingReport,
            'findingCategories' => $findingCategories,
            'activeScan' => $activeScan,
        ]);
    }

    public function findings(Website $site): JsonResponse
    {
        $this->authorizeOwner($site);

        $query = $site->findings()->open()->with('page');
        $severity = (string) request('severity', '');
        $category = (string) request('category', '');
        if (in_array($severity, ['critical', 'high', 'review', 'info'], true)) {
            $query->where('severity', $severity);
        }
        if (array_key_exists($category, UiText::findingCategories())) {
            $query->where('category', $category);
        }

        $findings = $query
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'review' then 3 else 4 end")
            ->orderByDesc('confidence')
            ->paginate(25);

        return response()->json([
            'data' => $findings->getCollection()->map(function ($finding) use ($site): array {
                $url = $finding->page?->url
                    ?? (filter_var(data_get($finding->signals, 'evidence_url'), FILTER_VALIDATE_URL) ? data_get($finding->signals, 'evidence_url') : $site->start_url);

                return [
                    'id' => $finding->public_id,
                    'url' => $url,
                    'path' => parse_url($url, PHP_URL_PATH) ?: '/',
                    'category' => UiText::label($finding->category),
                    'title' => UiText::text($finding->title),
                    'source' => data_get($finding->signals, 'analysis_source') === 'anthropic_web' ? 'Claude Web' : (str_starts_with($finding->rule_key, 'ai.') ? 'AI theo URL' : 'Crawler'),
                    'severity' => $finding->severity,
                    'severity_label' => UiText::label($finding->severity),
                    'confidence' => (int) $finding->confidence,
                    'evidence_url' => route('findings.show', $finding),
                ];
            })->values(),
            'meta' => [
                'current_page' => $findings->currentPage(),
                'last_page' => $findings->lastPage(),
                'total' => $findings->total(),
                'from' => $findings->firstItem(),
                'to' => $findings->lastItem(),
            ],
        ]);
    }

    public function assess(Website $site, AiConfiguration $configuration, WebsiteAiAssessmentDispatcher $dispatcher): RedirectResponse
    {
        $this->authorizeOwner($site);

        $scan = $site->scans()
            ->whereIn('status', [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL])
            ->latest('finished_at')
            ->first();
        if ($scan === null) {
            return back()->withErrors(['ai' => 'Website cần có ít nhất một lượt quét hoàn tất trước khi AI đánh giá.']);
        }
        if ($configuration->isWebReviewReady() && ! is_array(data_get($scan->meta, 'web_review'))) {
            return back()->withErrors(['ai' => 'Claude Web đang bật nhưng lượt quét này chưa có báo cáo realtime thành công. Hãy chạy lượt quét mới; hệ thống sẽ không thay thế âm thầm bằng bản tổng hợp crawler.']);
        }
        if (! $configuration->isReady() && ! is_array(data_get($scan->meta, 'web_review'))) {
            return back()->withErrors(['ai' => 'Lượt quét này chưa có báo cáo Claude Web và AI kiểm tra từng URL đang tắt. Hãy chạy một lượt quét mới có bật AI.']);
        }

        try {
            $queued = $dispatcher->dispatch($scan, 'manual');
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['ai' => 'Không thể đưa đánh giá AI vào hàng đợi: '.mb_substr($exception->getMessage(), 0, 300)]);
        }

        return back()->with('status', $queued
            ? 'Đã đưa yêu cầu đánh giá AI vào hàng đợi. Bạn có thể rời trang này trong khi hệ thống xử lý.'
            : 'Đánh giá AI của lượt quét này đang ở trong hàng đợi hoặc đang xử lý.');
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
        $severityUrlCounts = $website->findings()
            ->open()
            ->selectRaw('severity, count(distinct coalesce(page_id, 0)) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');
        $activeScan = $website->scans()
            ->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING])
            ->latest()
            ->first();
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
            'start_url' => $website->start_url,
            'score' => $website->overall_score,
            'status' => $website->status,
            'severity_url_counts' => collect(['critical', 'high', 'review', 'info'])
                ->mapWithKeys(fn (string $severity): array => [$severity => (int) ($severityUrlCounts[$severity] ?? 0)])
                ->all(),
            'scan_debug' => $activeScan ? [
                'status' => $activeScan->status,
                'progress' => (int) $activeScan->progress,
                'pages_scanned' => (int) $activeScan->pages_scanned,
                'pages_discovered' => (int) $activeScan->pages_discovered,
                'current_url' => $activeScan->current_url,
            ] : null,
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
