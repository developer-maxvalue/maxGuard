<?php

namespace App\Services;

use App\Data\DetectorResult;
use App\Data\PageDocument;
use App\Models\EvidenceItem;
use App\Models\Finding;
use App\Models\Page;
use App\Models\Scan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class EvidenceStore
{
    public function storePageSnapshot(Scan $scan, Page $page, PageDocument $document): string
    {
        $disk = (string) config('maxguard.evidence_disk', 'local');
        $prefix = trim((string) config('maxguard.evidence_prefix', 'maxguard/evidence'), '/');
        $contentHash = hash('sha256', $document->html);
        $path = "{$prefix}/website-{$scan->website_id}/scan-{$scan->id}/pages/{$page->url_hash}-{$contentHash}.html";

        if (! Storage::disk($disk)->put($path, $document->html)) {
            throw new RuntimeException('Unable to persist page evidence.');
        }

        return $path;
    }

    public function attach(
        Finding $finding,
        Scan $scan,
        PageDocument $document,
        string $snapshotPath,
        DetectorResult $result,
    ): void {
        $disk = (string) config('maxguard.evidence_disk', 'local');
        $htmlHash = hash('sha256', $document->html);

        EvidenceItem::firstOrCreate([
            'finding_id' => $finding->id,
            'scan_id' => $scan->id,
            'type' => 'html_snapshot',
            'sha256' => $htmlHash,
        ], [
            'disk' => $disk,
            'path' => $snapshotPath,
            'mime_type' => 'text/html',
            'size_bytes' => strlen($document->html),
            'metadata' => ['url' => $document->url, 'status_code' => $document->statusCode],
            'captured_at' => now(),
        ]);

        $payload = json_encode([
            'rule_key' => $result->ruleKey,
            'category' => $result->category,
            'severity' => $result->severity,
            'confidence' => $result->confidence,
            'signals' => $result->signals,
            'captured_url' => $document->url,
            'captured_at' => ($scan->started_at ?? $scan->created_at)->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $prefix = trim((string) config('maxguard.evidence_prefix', 'maxguard/evidence'), '/');
        $signalHash = hash('sha256', $payload);
        $signalPath = "{$prefix}/website-{$scan->website_id}/scan-{$scan->id}/signals/{$finding->public_id}-{$signalHash}.json";
        if (! Storage::disk($disk)->put($signalPath, $payload)) {
            throw new RuntimeException('Unable to persist detector evidence.');
        }

        EvidenceItem::firstOrCreate([
            'finding_id' => $finding->id,
            'scan_id' => $scan->id,
            'type' => 'detector_signal',
            'sha256' => $signalHash,
        ], [
            'disk' => $disk,
            'path' => $signalPath,
            'mime_type' => 'application/json',
            'size_bytes' => strlen($payload),
            'metadata' => ['rule_key' => $result->ruleKey],
            'captured_at' => now(),
        ]);
    }
}
