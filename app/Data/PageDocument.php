<?php

namespace App\Data;

final class PageDocument
{
    /**
     * @param list<string> $links
     * @param list<array{src: string, alt: string}> $images
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $url,
        public int $statusCode,
        public string $html,
        public string $text,
        public string $title,
        public ?string $canonicalUrl,
        public ?string $language,
        public int $wordCount,
        public int $adCount,
        public int $h1Count,
        public array $links,
        public array $images,
        public array $meta = [],
    ) {
    }

    public function contentHash(): string
    {
        return hash('sha256', $this->normalizedText());
    }

    public function normalizedText(): string
    {
        $text = mb_strtolower($this->text);
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public function isHomePage(): bool
    {
        $path = parse_url($this->url, PHP_URL_PATH) ?: '/';

        return $path === '/' || $path === '';
    }
}

