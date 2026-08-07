<?php

namespace App\Services;

use App\Models\Finding;

final class CopyrightEvidenceExtractor
{
    /** @return list<string> */
    public function sourceUrls(Finding $finding): array
    {
        $signals = (array) ($finding->signals ?? []);
        $urls = collect((array) ($signals['external_image_urls'] ?? []))
            ->merge((array) ($signals['source_urls'] ?? []));

        foreach (['source_url', 'matched_url'] as $key) {
            if (is_string($signals[$key] ?? null)) {
                $urls->push($signals[$key]);
            }
        }
        $manualSource = $finding->page?->copyrightReviews?->first()?->matched_url;
        if (is_string($manualSource) && $manualSource !== '') {
            $urls->push($manualSource);
        }

        return $urls
            ->filter(fn ($url): bool => is_string($url) && filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

}
