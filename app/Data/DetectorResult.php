<?php

namespace App\Data;

use InvalidArgumentException;

final class DetectorResult
{
    /**
     * @param array<string, mixed> $signals
     * @param list<string> $remediation
     */
    public function __construct(
        public string $ruleKey,
        public string $category,
        public string $severity,
        public int $confidence,
        public string $title,
        public string $summary,
        public ?string $policyReference = null,
        public array $signals = [],
        public array $remediation = [],
        public string $fingerprintSalt = '',
    ) {
        if (! in_array($severity, ['critical', 'high', 'review', 'info'], true)) {
            throw new InvalidArgumentException("Unsupported severity [{$severity}].");
        }

        $this->confidence = max(0, min(100, $confidence));
    }

    public function fingerprint(string $url): string
    {
        return hash('sha256', implode('|', [
            $this->ruleKey,
            mb_strtolower($url),
            $this->fingerprintSalt,
        ]));
    }
}

