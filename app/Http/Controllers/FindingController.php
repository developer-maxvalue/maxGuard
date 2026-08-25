<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFindingRequest;
use App\Models\Finding;
use App\Models\Scan;
use App\Support\GooglePolicyReference;
use App\Support\UiText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FindingController extends Controller
{
    public function index(): View|StreamedResponse
    {
        $query = $this->filteredQuery()->latest('last_seen_at');

        if (request('export') === 'csv') {
            return $this->exportCsv($query);
        }

        $findings = $query->paginate(30)->withQueryString();

        return view('findings.index', [
            'findings' => $findings->through(fn (Finding $finding): array => $this->row($finding)),
            'counts' => [
                'critical' => $this->visibleFindings()->open()->where('severity', 'critical')->count(),
                'high' => $this->visibleFindings()->open()->where('severity', 'high')->count(),
                'remediating' => $this->visibleFindings()->where('status', 'remediating')->count(),
                'resolved_month' => $this->visibleFindings()->where('status', 'resolved')->where('resolved_at', '>=', now()->startOfMonth())->count(),
            ],
        ]);
    }

    public function exportXlsx(): StreamedResponse
    {
        $query = $this->filteredQuery();

        return response()->streamDownload(function () use ($query): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Phát hiện');
            $headers = [
                'Mã phát hiện', 'Mã lượt quét', 'Website', 'URL', 'Nguồn', 'Mã quy tắc', 'Danh mục', 'Mức độ',
                'Độ tin cậy', 'Trạng thái', 'Tiêu đề', 'Tóm tắt', 'Tham chiếu chính sách',
                'Phát hiện lần đầu', 'Phát hiện gần nhất', 'Khắc phục',
            ];
            $sheet->fromArray($headers, null, 'A1');
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:P1');
            $sheet->getStyle('A1:P1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            ]);

            $row = 2;
            $query->reorder('id')->chunkById(500, function ($findings) use ($sheet, &$row): void {
                foreach ($findings as $finding) {
                    $values = [
                        $finding->public_id,
                        (string) $finding->scan_id,
                        $finding->website->domain,
                        $this->findingUrl($finding),
                        $this->findingSource($finding),
                        $finding->rule_key,
                        UiText::label($finding->category),
                        UiText::label($finding->severity),
                        (string) $finding->confidence,
                        UiText::label($finding->status),
                        UiText::text($finding->title),
                        UiText::text($finding->summary),
                        UiText::text($finding->policy_reference),
                        $finding->first_seen_at->toIso8601String(),
                        $finding->last_seen_at->toIso8601String(),
                        implode("\n", UiText::texts((array) $finding->remediation)),
                    ];
                    foreach ($values as $column => $value) {
                        $coordinate = Coordinate::stringFromColumnIndex($column + 1).$row;
                        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                    }
                    $row++;
                }
            });

            foreach (range('A', 'P') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize($column !== 'L' && $column !== 'P');
            }
            $sheet->getColumnDimension('L')->setWidth(60);
            $sheet->getColumnDimension('P')->setWidth(55);
            $sheet->getStyle('A1:P'.max(1, $row - 1))->getAlignment()->setVertical('top')->setWrapText(true);

            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'maxguard-findings-'.now()->format('Y-m-d-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Finding $finding): View
    {
        $this->authorizeOwner($finding);
        $finding->load(['website', 'page']);
        $rawSignals = (array) ($finding->signals ?? []);

        $evidenceQuotes = collect((array) ($rawSignals['evidence'] ?? []))
            ->merge((array) ($rawSignals['matching_phrases'] ?? []))
            ->filter(fn ($quote): bool => is_scalar($quote) && trim((string) $quote) !== '')
            ->map(fn ($quote): string => trim((string) $quote))
            ->unique()
            ->take(12)
            ->values()
            ->all();
        $duplicateMatches = [];
        if (is_string($rawSignals['matched_url'] ?? null) && $rawSignals['matched_url'] !== '') {
            $duplicateMatches[] = [
                'source_url' => $finding->page?->url ?? $finding->website->start_url,
                'matched_url' => $rawSignals['matched_url'],
                'similarity' => isset($rawSignals['similarity']) ? (int) $rawSignals['similarity'] : null,
                'method' => (string) ($rawSignals['method'] ?? ''),
            ];
        }
        $citations = collect((array) ($rawSignals['citations'] ?? []))
            ->filter(fn ($citation): bool => is_array($citation))
            ->filter(fn (array $citation): bool => filter_var($citation['url'] ?? null, FILTER_VALIDATE_URL) !== false)
            ->map(fn (array $citation): array => [
                'url' => (string) $citation['url'],
                'title' => trim((string) ($citation['title'] ?? 'Nguồn được Claude Web đọc')),
                'cited_text' => trim((string) ($citation['cited_text'] ?? '')),
            ])->take(8)->values()->all();
        $policyUrl = filter_var($rawSignals['policy_url'] ?? null, FILTER_VALIDATE_URL)
            ? (string) $rawSignals['policy_url']
            : GooglePolicyReference::url($finding->category, $finding->policy_reference);

        return view('findings.show', ['finding' => [
            ...$this->row($finding),
            'url' => $this->findingUrl($finding),
            'policy' => $finding->policy_reference ? UiText::text($finding->policy_reference) : 'Cần đối chiếu chính sách thủ công',
            'policy_url' => $policyUrl,
            'summary' => UiText::text($finding->summary),
            'evidence_quotes' => $evidenceQuotes,
            'citations' => $citations,
            'duplicate_matches' => $duplicateMatches,
            'page_id' => $finding->page?->id,
        ]]);
    }

    public function update(UpdateFindingRequest $request, Finding $finding): RedirectResponse
    {
        $this->authorizeOwner($finding);
        $data = $request->validated();
        $finding->update([
            'status' => $data['status'],
            'assigned_to' => $data['assigned_to'] ?? $finding->assigned_to,
            'resolved_at' => $data['status'] === 'resolved' ? now() : null,
        ]);

        return back()->with('status', 'Đã cập nhật trạng thái xử lý phát hiện.');
    }

    private function row(Finding $finding): array
    {
        return [
            'id' => $finding->public_id,
            'site' => $finding->website->domain,
            'title' => UiText::text($finding->title),
            'category' => UiText::label($finding->category),
            'source' => $this->findingSource($finding),
            'severity' => $finding->severity,
            'confidence' => $finding->confidence,
            'affected' => $finding->page_id ? '1 URL' : 'Toàn website',
            'url' => $this->findingUrl($finding),
            'detected' => $finding->last_seen_at->diffForHumans(),
            'status' => $finding->status,
        ];
    }

    private function findingUrl(Finding $finding): string
    {
        if ($finding->page?->url) {
            return $finding->page->url;
        }
        $evidenceUrl = data_get($finding->signals, 'evidence_url');

        return filter_var($evidenceUrl, FILTER_VALIDATE_URL) ? (string) $evidenceUrl : $finding->website->start_url;
    }

    private function findingSource(Finding $finding): string
    {
        if (data_get($finding->signals, 'analysis_source') === 'anthropic_web') {
            return 'Claude Web';
        }

        return str_starts_with($finding->rule_key, 'ai.') ? 'AI theo URL' : 'Crawler';
    }

    private function visibleFindings(): Builder
    {
        return Finding::query()->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()));
    }

    private function filteredQuery(): Builder
    {
        $query = Finding::query()
            ->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()))
            ->with(['website', 'page']);

        if (request()->filled('severity')) {
            $query->where('severity', request('severity'));
        }
        if (request()->filled('category')) {
            $query->where('category', request('category'));
        }
        if (request()->filled('status')) {
            $query->where('status', request('status'));
        }
        if (request()->filled('scan_id')) {
            $query->where('scan_id', (int) request('scan_id'));
        }
        if (request()->boolean('active_scan')) {
            $query->whereHas('scan', fn ($scan) => $scan->whereIn('status', [Scan::STATUS_QUEUED, Scan::STATUS_RUNNING]));
        }
        if (request()->filled('q')) {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) request('q')).'%';
            $query->where(function ($query) use ($search): void {
                $query->where('title', 'like', $search)
                    ->orWhere('summary', 'like', $search)
                    ->orWhere('public_id', 'like', $search)
                    ->orWhereHas('website', fn ($query) => $query->where('domain', 'like', $search))
                    ->orWhereHas('page', fn ($query) => $query->where('url', 'like', $search));
            });
        }

        return $query;
    }

    private function authorizeOwner(Finding $finding): void
    {
        abort_if(
            auth()->id() !== null
            && ! auth()->user()?->is_admin
            && $finding->website->user_id !== auth()->id(),
            403
        );
    }

    private function exportCsv(Builder $query): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Mã', 'Website', 'URL', 'Danh mục', 'Mức độ', 'Độ tin cậy', 'Trạng thái', 'Tiêu đề', 'Phát hiện gần nhất']);
            $query->reorder('id')->chunkById(500, function ($findings) use ($output): void {
                foreach ($findings as $finding) {
                    fputcsv($output, [
                        $finding->public_id,
                        $finding->website->domain,
                        $this->findingUrl($finding),
                        UiText::label($finding->category),
                        UiText::label($finding->severity),
                        $finding->confidence,
                        UiText::label($finding->status),
                        UiText::text($finding->title),
                        $finding->last_seen_at->toIso8601String(),
                    ]);
                }
            });
            fclose($output);
        }, 'maxguard-findings-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
