<?php

namespace App\Data;

final class CrawlResponse
{
    /** @param array<string, string|array<string>> $headers */
    public function __construct(
        public string $url,
        public int $status,
        public string $body,
        public array $headers = [],
    ) {
    }
}

