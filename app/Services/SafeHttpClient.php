<?php

namespace App\Services;

use App\Data\CrawlResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SafeHttpClient
{
    public function __construct(
        private SafeUrlValidator $validator,
        private UrlNormalizer $urls,
    ) {
    }

    public function get(string $url, string $accept = 'text/html,application/xhtml+xml'): CrawlResponse
    {
        $maxRedirects = (int) config('maxguard.crawler.max_redirects', 4);
        $current = $this->urls->normalize($url);

        for ($redirect = 0; $redirect <= $maxRedirects; $redirect++) {
            $ips = $this->validator->publicIps($current);
            $response = $this->request($current, $ips[0], $accept);

            if ($response->redirect()) {
                $location = $response->header('Location');
                $next = is_string($location) ? $this->urls->resolve($current, $location) : null;
                if ($next === null) {
                    throw new RuntimeException('The remote server returned an invalid redirect.');
                }
                $current = $next;
                continue;
            }

            $body = $response->body();
            $limit = (int) config('maxguard.crawler.max_response_bytes', 5_000_000);
            $declaredLength = (int) ($response->header('Content-Length') ?: 0);
            if ($declaredLength > $limit || strlen($body) > $limit) {
                throw new RuntimeException("Response exceeded the {$limit}-byte safety limit.");
            }

            return new CrawlResponse($current, $response->status(), $body, $response->headers());
        }

        throw new RuntimeException('Maximum redirect count exceeded.');
    }

    /** @param list<string> $ips */
    private function request(string $url, string $pinnedIp, string $accept): Response
    {
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));

        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'progress' => function ($downloadTotal, $downloadedBytes): void {
                $limit = (int) config('maxguard.crawler.max_response_bytes', 5_000_000);
                if ($downloadedBytes > $limit) {
                    throw new RuntimeException("Response exceeded the {$limit}-byte safety limit.");
                }
            },
        ];

        if (defined('CURLOPT_RESOLVE')) {
            $resolvedAddress = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolvedAddress}"]];
        }

        return Http::withHeaders([
            'Accept' => $accept,
            'User-Agent' => (string) config('maxguard.crawler.user_agent'),
        ])->withOptions($options)
            ->connectTimeout((int) config('maxguard.crawler.connect_timeout_seconds', 8))
            ->timeout((int) config('maxguard.crawler.timeout_seconds', 20))
            ->get($url);
    }
}
