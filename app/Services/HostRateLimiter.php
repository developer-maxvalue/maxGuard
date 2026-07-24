<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class HostRateLimiter
{
    public function throttle(string $url): void
    {
        $requestsPerSecond = max(0.1, (float) config('maxguard.crawler.requests_per_second', 1.5));
        $interval = 1 / $requestsPerSecond;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return;
        }

        $key = 'maxguard:host-rate:'.hash('sha256', $host);
        $lock = Cache::lock($key.':lock', 15);

        try {
            $lock->block(10, function () use ($key, $interval): void {
                $now = microtime(true);
                $nextRequestAt = (float) Cache::get($key, 0);
                if ($nextRequestAt > $now) {
                    usleep((int) min(10_000_000, round(($nextRequestAt - $now) * 1_000_000)));
                }

                Cache::put($key, microtime(true) + $interval, now()->addHour());
            });
        } catch (LockTimeoutException) {
            usleep((int) min(10_000_000, round($interval * 1_000_000)));
        }
    }
}
