<?php

namespace App\Data;

final class AiAnalysisOutcome
{
    /** @param list<DetectorResult> $findings */
    public function __construct(
        public bool $attempted,
        public array $findings = [],
        public ?string $model = null,
        public ?string $responseId = null,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public ?string $error = null,
    ) {
    }

    public static function skipped(): self
    {
        return new self(false);
    }
}
