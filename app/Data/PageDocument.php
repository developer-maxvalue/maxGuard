<?php

namespace App\Data;

final class PageDocument
{
    /**
     * @param  list<string>  $links
     * @param  list<array{src: string, alt: string}>  $images
     * @param  array<string, mixed>  $meta
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
    ) {}

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

    /**
     * Listing pages repeat article excerpts, navigation and template text by
     * design. They must not be treated as standalone articles when comparing
     * duplicate or externally copied content.
     */
    public function isContentListingPage(): bool
    {
        $path = rawurldecode((string) (parse_url($this->url, PHP_URL_PATH) ?: '/'));
        $path = mb_strtolower(trim($path, '/'));
        if (preg_match('~(?:^|/)(?:tag|tags|category|categories|author|search)(?:/|$)~u', $path) === 1) {
            return true;
        }

        // Blog/archive pagination, e.g. /blog/page/2. A normal article whose
        // slug merely contains the word "page" is not matched.
        if (preg_match('~(?:^|/)(?:blog|news|archive|archives)/page/\d+(?:/|$)~u', $path) === 1) {
            return true;
        }

        $query = [];
        parse_str((string) (parse_url($this->url, PHP_URL_QUERY) ?: ''), $query);
        foreach (['tag', 'cat', 'category', 'author', 's', 'search'] as $key) {
            if (array_key_exists($key, $query)) {
                return true;
            }
        }

        $bodyClass = mb_strtolower((string) ($this->meta['body_class'] ?? ''));

        return preg_match('~(?:^|\s)(?:archive|category|tag|tax|author|search|post-type-archive)(?:-|\s|$)~u', $bodyClass) === 1;
    }

    public function pageType(): string
    {
        if ($this->isHomePage()) {
            return 'home';
        }

        return $this->isContentListingPage() ? 'listing' : 'content';
    }
}
