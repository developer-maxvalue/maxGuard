<?php

namespace App\Services;

final class SitemapParser
{
    /**
     * @return array{
     *     type: 'index'|'urlset'|'unknown',
     *     locations: list<string>,
     *     entries: list<array{loc: string, lastmod: string|null}>
     * }
     */
    public function parse(string $body): array
    {
        if (str_starts_with($body, "\x1f\x8b") && function_exists('gzdecode')) {
            $decoded = gzdecode($body);
            if (is_string($decoded)) {
                $body = $decoded;
            }
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return ['type' => 'unknown', 'locations' => [], 'entries' => []];
        }

        $root = strtolower($xml->getName());
        $query = match ($root) {
            'sitemapindex' => '/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]',
            'urlset' => '/*[local-name()="urlset"]/*[local-name()="url"]',
            default => null,
        };

        if ($query === null) {
            return ['type' => 'unknown', 'locations' => [], 'entries' => []];
        }

        $entries = [];
        foreach ($xml->xpath($query) ?: [] as $node) {
            $locations = $node->xpath('./*[local-name()="loc"]') ?: [];
            $lastModifiedValues = $node->xpath('./*[local-name()="lastmod"]') ?: [];
            $location = $locations[0] ?? null;
            $lastModified = $lastModifiedValues[0] ?? null;
            $url = trim((string) $location);
            if ($url !== '' && ! isset($entries[$url])) {
                $lastmod = trim((string) $lastModified);
                $entries[$url] = [
                    'loc' => $url,
                    'lastmod' => $lastmod !== '' ? $lastmod : null,
                ];
            }
        }

        $entries = array_values($entries);

        return [
            'type' => $root === 'sitemapindex' ? 'index' : 'urlset',
            'locations' => array_column($entries, 'loc'),
            'entries' => $entries,
        ];
    }
}
