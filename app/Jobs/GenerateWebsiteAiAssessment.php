<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\AiConfiguration;
use App\Services\AnthropicWebReviewer;
use App\Services\WebsiteAiReviewer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class GenerateWebsiteAiAssessment implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public int $tries = 3;

    public int $uniqueFor;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $scanId)
    {
        $requestTimeout = (int) config('maxguard.ai.timeout_seconds', 90);
        if ((string) config('maxguard.ai.provider') === 'anthropic') {
            $requestTimeout = max($requestTimeout, (int) config('maxguard.ai.anthropic_timeout_seconds', 300));
        }
        if ((bool) config('maxguard.review_ai.enabled', false)) {
            $requestTimeout = max($requestTimeout, (int) config('maxguard.review_ai.timeout_seconds', 300));
        }
        $this->timeout = max(360, $requestTimeout + 120);
        $this->uniqueFor = $this->timeout * $this->tries + 600;
    }

    public function uniqueId(): string
    {
        return 'maxguard-ai-assessment-'.$this->scanId;
    }

    public function handle(
        AiConfiguration $configuration,
        WebsiteAiReviewer $reviewer,
        AnthropicWebReviewer $webReviewer,
    ): void
    {
        $configuration->apply();
        $scan = Scan::query()->findOrFail($this->scanId);
        if (! in_array($scan->status, [Scan::STATUS_COMPLETED, Scan::STATUS_PARTIAL], true)) {
            return;
        }

        // A manual assessment may target an older, already completed scan that
        // predates realtime review. Run Claude Web now instead of forcing the
        // user to crawl the website again.
        $refreshWebReview = (bool) data_get($scan->meta, 'web_review_refresh_requested', false);
        if (($refreshWebReview || ! is_array(data_get($scan->meta, 'web_review'))) && $configuration->isWebReviewReady()) {
            $this->updateMeta($scan, [
                'web_review_status' => 'running',
                'web_review_started_at' => now()->toIso8601String(),
                'web_review_error' => null,
                'web_review_failed_at' => null,
                'web_review_refresh_requested' => false,
            ]);

            try {
                $webReviewer->reviewAndStore($scan->fresh());
                $scan = $scan->fresh();
            } catch (Throwable $exception) {
                $this->updateMeta($scan->fresh(), [
                    'web_review_status' => 'failed',
                    'web_review_error' => mb_substr($exception->getMessage(), 0, 1000),
                    'web_review_failed_at' => now()->toIso8601String(),
                ]);

                throw new RuntimeException('Claude Web không thể tạo báo cáo realtime: '.$exception->getMessage(), 0, $exception);
            }
        }
        if (! is_array(data_get($scan->meta, 'web_review')) && ! $configuration->isReady()) {
            throw new RuntimeException('AI chưa được cấu hình hoặc đã bị tắt.');
        }

        $this->updateMeta($scan, [
            'ai_assessment_status' => 'running',
            'ai_assessment_started_at' => now()->toIso8601String(),
            'ai_assessment_attempt' => $this->attempts(),
            'ai_assessment_error' => null,
        ]);

        try {
            $reviewer->reviewAndStore($scan->fresh());
            $this->updateMeta($scan->fresh(), [
                'ai_assessment_status' => 'completed',
                'ai_assessment_completed_at' => now()->toIso8601String(),
                'ai_assessment_error' => null,
                'ai_assessment_failed_at' => null,
            ]);
        } catch (Throwable $exception) {
            $this->updateMeta($scan->fresh(), [
                'ai_assessment_status' => 'retrying',
                'ai_assessment_error' => mb_substr($exception->getMessage(), 0, 1000),
                'ai_assessment_last_error_at' => now()->toIso8601String(),
            ]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        $scan = Scan::query()->find($this->scanId);
        if ($scan === null) {
            return;
        }

        $this->updateMeta($scan, [
            'ai_assessment_status' => 'failed',
            'ai_assessment_error' => mb_substr($exception->getMessage(), 0, 1000),
            'ai_assessment_failed_at' => now()->toIso8601String(),
        ]);
    }

    /** @param array<string, mixed> $updates */
    private function updateMeta(Scan $scan, array $updates): void
    {
        $scan->update(['meta' => array_merge((array) $scan->meta, $updates)]);
    }
}
