<?php

namespace App\Services;

use App\Models\ScanTarget;
use App\Models\ScanTargetEvent;

/**
 * Writes sanitized progress events used by the scan/URL debug screens.
 *
 * This service intentionally accepts only explicitly selected context. API
 * credentials, authorization headers and full article content are never saved.
 */
final class ScanTelemetry
{
    /**
     * Mark a stage as running and return a monotonic start time for duration.
     */
    public function start(ScanTarget $target, string $stage, string $service, string $message): float
    {
        $target->update(['current_stage' => $stage]);
        ScanTargetEvent::query()->create([
            'scan_id' => $target->scan_id,
            'scan_target_id' => $target->id,
            'stage' => $stage,
            'status' => 'running',
            'service' => $service,
            'message' => mb_substr($message, 0, 5000),
            'started_at' => now(),
        ]);

        return microtime(true);
    }

    /**
     * Close the latest running stage and store safe response/error metadata.
     *
     * @param array<string, mixed> $context
     */
    public function finish(
        ScanTarget $target,
        string $stage,
        float $started,
        string $status = 'success',
        string $message = 'Completed',
        array $context = [],
    ): void {
        $event = ScanTargetEvent::query()
            ->where('scan_target_id', $target->id)
            ->where('stage', $stage)
            ->where('status', 'running')
            ->latest('id')
            ->first();
        if ($event === null) {
            return;
        }

        $event->update([
            'status' => $status,
            'duration_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
            'http_status' => $context['http_status'] ?? null,
            'request_id' => $context['request_id'] ?? null,
            'message' => mb_substr($message, 0, 5000),
            'context' => $this->sanitize($context),
            'finished_at' => now(),
        ]);
    }

    /**
     * Redact common credential keys and limit serialized debug payload size.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        foreach (['api_key', 'api_secret', 'api_user', 'access_token', 'refresh_token', 'authorization', 'text', 'content'] as $key) {
            unset($context[$key]);
        }
        $encoded = json_encode($context);

        return strlen((string) $encoded) > 20000
            ? ['truncated' => true, 'summary' => mb_substr((string) $encoded, 0, 19000)]
            : $context;
    }
}
