<?php

namespace App\Services;

use App\Models\Website;
use App\Models\WebsiteGa4Connection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the previous seven complete days from the GA4 Data API and stores page
 * views on canonical local Page rows. Rows are returned highest traffic first.
 */
final class Ga4TrafficService
{
    /** @return array<string, int> URL path => views */
    public function sync(Website $website): array
    {
        $connection = $website->ga4Connection;
        if (! $connection || blank($connection->property_id)) {
            throw new RuntimeException('GA4 is not connected or a property ID has not been configured.');
        }

        $token = $this->validAccessToken($connection);
        $response = Http::withToken($token)->acceptJson()->post(
            'https://analyticsdata.googleapis.com/v1beta/properties/'.rawurlencode($connection->property_id).':runReport',
            [
                'dateRanges' => [[
                    'startDate' => max(1, (int) config('maxguard.ga4.traffic_days', 7)).'daysAgo',
                    'endDate' => 'yesterday',
                ]],
                'dimensions' => [['name' => 'pagePath']],
                'metrics' => [['name' => 'screenPageViews']],
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => (string) max(1, (int) config('maxguard.ga4.max_rows', 1000)),
            ],
        );
        if (! $response->successful()) {
            $connection->update(['last_error' => 'GA4 Data API HTTP '.$response->status()]);
            throw new RuntimeException('GA4 Data API returned HTTP '.$response->status().'.');
        }

        $traffic = [];
        foreach ((array) $response->json('rows', []) as $row) {
            $path = (string) data_get($row, 'dimensionValues.0.value', '');
            $traffic[$path] = (int) data_get($row, 'metricValues.0.value', 0);
        }

        foreach ($website->pages()->get() as $page) {
            $path = parse_url($page->url, PHP_URL_PATH) ?: '/';
            $page->update(['ga4_views_7d' => $traffic[$path] ?? 0, 'ga4_synced_at' => now()]);
        }
        $connection->update(['last_synced_at' => now(), 'last_error' => null]);

        return $traffic;
    }

    /**
     * Return a usable access token, refreshing it with the encrypted refresh
     * token when its expiry window has elapsed.
     */
    private function validAccessToken(WebsiteGa4Connection $connection): string
    {
        if ($connection->token_expires_at?->isFuture() && filled($connection->access_token)) {
            return (string) $connection->access_token;
        }
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('maxguard.ga4.client_id'),
            'client_secret' => config('maxguard.ga4.client_secret'),
            'refresh_token' => $connection->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (! $response->successful()) {
            throw new RuntimeException('Unable to refresh the Google OAuth token.');
        }
        $connection->update([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)),
        ]);

        return (string) $connection->fresh()->access_token;
    }
}
