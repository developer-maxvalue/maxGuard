<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

final class SafeUrlValidator
{
    /** @return list<string> */
    public function publicIps(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('A fully-qualified URL is required.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs may be scanned.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Credential-bearing URLs are not allowed.');
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($port, [80, 443], true)) {
            throw new InvalidArgumentException('Only ports 80 and 443 may be scanned.');
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException('Local and internal hostnames are not allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->resolve($host);

        if ($ips === []) {
            throw new RuntimeException("Unable to resolve host [{$host}].");
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new InvalidArgumentException("Host [{$host}] resolves to a private or reserved address.");
            }
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return list<string> */
    private function resolve(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) {
                $ips = $fallback;
            }
        }

        $ips = array_values(array_filter($ips, fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        usort($ips, fn (string $first, string $second): int => (int) (filter_var($second, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) <=> (int) (filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return $ips;
    }
}
