<?php

namespace App\Services;

use App\Jobs\GenerateWebsiteAiAssessment;
use App\Models\Scan;
use Throwable;

final class WebsiteAiAssessmentDispatcher
{
    public function dispatch(Scan $scan, string $source = 'automatic'): bool
    {
        $status = (string) data_get($scan->meta, 'ai_assessment_status', '');
        if (in_array($status, ['queued', 'running', 'retrying'], true)) {
            return false;
        }

        $meta = array_merge((array) $scan->meta, [
            'ai_assessment_status' => 'queued',
            'ai_assessment_source' => $source,
            'ai_assessment_queued_at' => now()->toIso8601String(),
            'ai_assessment_error' => null,
            'ai_assessment_failed_at' => null,
        ]);
        $scan->update(['meta' => $meta]);

        try {
            GenerateWebsiteAiAssessment::dispatch($scan->id)
                ->onQueue((string) config('maxguard.ai_assessment_queue', config('maxguard.finalize_queue', 'scan-finalize')));
        } catch (Throwable $exception) {
            $scan->refresh();
            $scan->update(['meta' => array_merge((array) $scan->meta, [
                'ai_assessment_status' => 'failed',
                'ai_assessment_error' => mb_substr($exception->getMessage(), 0, 1000),
                'ai_assessment_failed_at' => now()->toIso8601String(),
            ])]);

            throw $exception;
        }

        return true;
    }
}
