<?php

namespace App\Jobs;

use App\Models\Scan;
use App\Services\AiConfiguration;
use App\Services\AnthropicWebReviewer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class RunAnthropicWebReview implements ShouldBeUnique, ShouldQueue
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
        $requestTimeout = max(
            (int) config('maxguard.review_ai.timeout_seconds', 300),
            300,
        );
        $this->timeout = max(360, $requestTimeout + 120);
        $this->uniqueFor = $this->timeout * $this->tries + 600;
    }

    public function uniqueId(): string
    {
        return 'maxguard-anthropic-web-review-'.$this->scanId;
    }

    public function handle(AiConfiguration $configuration, AnthropicWebReviewer $reviewer): void
    {
        $configuration->apply();
        $scan = Scan::query()->findOrFail($this->scanId);
        if (in_array($scan->status, [Scan::STATUS_FAILED, Scan::STATUS_CANCELLED], true)) {
            return;
        }
        if (! $configuration->isWebReviewReady()) {
            throw new RuntimeException('Claude Web Review yêu cầu cấu hình Anthropic hợp lệ.');
        }

        $reviewer->mergeMeta($scan->id, [
            'web_review_status' => 'running',
            'web_review_started_at' => now()->toIso8601String(),
            'web_review_attempt' => $this->attempts(),
            'web_review_error' => null,
        ]);

        try {
            $reviewer->reviewAndStore($scan->fresh());
        } catch (Throwable $exception) {
            $reviewer->mergeMeta($scan->id, [
                'web_review_status' => 'retrying',
                'web_review_error' => mb_substr($exception->getMessage(), 0, 1000),
                'web_review_last_error_at' => now()->toIso8601String(),
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
        app(AnthropicWebReviewer::class)->mergeMeta($scan->id, [
            'web_review_status' => 'failed',
            'web_review_error' => mb_substr($exception->getMessage(), 0, 1000),
            'web_review_failed_at' => now()->toIso8601String(),
        ]);
    }
}
