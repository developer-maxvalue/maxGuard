<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFindingRequest;
use App\Models\Finding;
use App\Models\Scan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
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
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Findings');
            $headers = [
                'Finding ID', 'Scan ID', 'Website', 'URL', 'Source', 'Rule key', 'Category', 'Severity',
                'Confidence', 'Status', 'Title', 'Summary', 'Policy reference', 'Revenue impact',
                'First seen', 'Last seen', 'Remediation',
            ];
            $sheet->fromArray($headers, null, 'A1');
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:Q1');
            $sheet->getStyle('A1:Q1')->applyFromArray([
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
                        $finding->page?->url ?? $finding->website->start_url,
                        str_starts_with($finding->rule_key, 'ai.') ? 'AI' : 'Rules',
                        $finding->rule_key,
                        $finding->category,
                        $finding->severity,
                        (string) $finding->confidence,
                        $finding->status,
                        $finding->title,
                        $finding->summary,
                        $finding->policy_reference ?? '',
                        (string) $finding->revenue_impact,
                        $finding->first_seen_at->toIso8601String(),
                        $finding->last_seen_at->toIso8601String(),
                        implode("\n", (array) $finding->remediation),
                    ];
                    foreach ($values as $column => $value) {
                        $coordinate = Coordinate::stringFromColumnIndex($column + 1).$row;
                        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                    }
                    $row++;
                }
            });

            foreach (range('A', 'Q') as $column) {
                $sheet->getColumnDimension($column)->setAutoSize($column !== 'L' && $column !== 'Q');
            }
            $sheet->getColumnDimension('L')->setWidth(60);
            $sheet->getColumnDimension('Q')->setWidth(55);
            $sheet->getStyle('A1:Q'.max(1, $row - 1))->getAlignment()->setVertical('top')->setWrapText(true);

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
        $finding->load(['website', 'page', 'evidenceItems' => fn ($query) => $query->latest('captured_at')]);
        $signals = collect($finding->signals ?? [])->map(function ($value, string $key): array {
            return [
                'label' => Str::headline($key),
                'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (is_scalar($value) ? (string) $value : 'Recorded'),
                'detail' => 'Captured by the automated detector for this finding.',
            ];
        })->values()->all();

        $timeline = [
            ['time' => $finding->first_seen_at->format('H:i'), 'title' => 'Finding created', 'detail' => 'Automated detector created this case.'],
        ];
        foreach ($finding->evidenceItems->take(3) as $evidence) {
            $timeline[] = ['time' => $evidence->captured_at->format('H:i'), 'title' => Str::headline($evidence->type), 'detail' => 'Immutable evidence stored with SHA-256 integrity hash.'];
        }

        return view('findings.show', ['finding' => [
            ...$this->row($finding),
            'url' => $finding->page?->url ?? $finding->website->start_url,
            'policy' => $finding->policy_reference ?? 'Manual policy mapping required',
            'summary' => $finding->summary,
            'signals' => $signals,
            'actions' => $finding->remediation ?? ['Review the evidence and document the remediation decision.'],
            'timeline' => $timeline,
            'evidence' => $finding->evidenceItems,
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

        return back()->with('status', 'Finding workflow status updated.');
    }

    private function row(Finding $finding): array
    {
        return [
            'id' => $finding->public_id,
            'site' => $finding->website->domain,
            'title' => $finding->title,
            'category' => $finding->category,
            'source' => str_starts_with($finding->rule_key, 'ai.') ? 'AI' : 'Rules',
            'severity' => $finding->severity,
            'confidence' => $finding->confidence,
            'affected' => $finding->page_id ? '1 URL' : 'Site-wide',
            'detected' => $finding->last_seen_at->diffForHumans(),
            'status' => $finding->status,
        ];
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
        abort_if(auth()->id() !== null && $finding->website->user_id !== auth()->id(), 403);
    }

    private function exportCsv(Builder $query): StreamedResponse
    {
        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['ID', 'Website', 'URL', 'Category', 'Severity', 'Confidence', 'Status', 'Title', 'Last seen']);
            $query->reorder('id')->chunkById(500, function ($findings) use ($output): void {
                foreach ($findings as $finding) {
                    fputcsv($output, [
                        $finding->public_id,
                        $finding->website->domain,
                        $finding->page?->url,
                        $finding->category,
                        $finding->severity,
                        $finding->confidence,
                        $finding->status,
                        $finding->title,
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
