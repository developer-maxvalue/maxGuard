<?php

namespace App\Services;

use App\Models\Website;

final class WebsiteVerificationService
{
    public function __construct(
        private SafeHttpClient $http,
        private UrlNormalizer $urls,
    ) {
    }

    public function verify(Website $website): bool
    {
        $parts = parse_url($website->start_url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $response = $this->http->get($origin.'/.well-known/maxguard-verification.txt', 'text/plain,*/*');
        $expected = (string) data_get($website->settings, 'verification_token');

        if ($expected === '' || $response->status >= 400 || ! $this->urls->sameHost($website->start_url, $response->url) || ! hash_equals($expected, trim($response->body))) {
            return false;
        }

        $website->update(['ownership_verified_at' => now()]);

        return true;
    }
}
