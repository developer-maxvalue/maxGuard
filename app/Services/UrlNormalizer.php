<?php

namespace App\Services;

final class UrlNormalizer
{
    public function normalize(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower(rtrim($parts['host'], '.'));
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $this->normalizePath($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public function resolve(string $baseUrl, string $candidate): ?string
    {
        $candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5);

        if ($candidate === '' || str_starts_with($candidate, '#')) {
            return null;
        }

        if (preg_match('/^(mailto|tel|javascript|data):/i', $candidate)) {
            return null;
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL)) {
            return $this->normalize($candidate);
        }

        $base = parse_url($baseUrl);
        if ($base === false || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return $this->normalize($base['scheme'].':'.$candidate);
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');
        $candidateParts = parse_url($candidate);
        $candidatePath = $candidateParts['path'] ?? '';
        $query = isset($candidateParts['query']) ? '?'.$candidateParts['query'] : '';

        if (str_starts_with($candidatePath, '/')) {
            return $this->normalize($origin.$candidatePath.$query);
        }

        $basePath = $base['path'] ?? '/';
        $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath).'/';

        return $this->normalize($origin.$directory.$candidatePath.$query);
    }

    public function sameHost(string $first, string $second): bool
    {
        return strtolower((string) parse_url($first, PHP_URL_HOST)) === strtolower((string) parse_url($second, PHP_URL_HOST));
    }

    private function normalizePath(string $path): string
    {
        $hadTrailingSlash = $path !== '/' && str_ends_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        $normalized = '/'.implode('/', $segments);
        if ($hadTrailingSlash && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized === '' ? '/' : $normalized;
    }
}
