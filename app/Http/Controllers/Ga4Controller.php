<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Services\Ga4TrafficService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class Ga4Controller extends Controller
{
    /** Redirect the site owner to Google's read-only Analytics consent page. */
    public function connect(Website $site): RedirectResponse
    {
        $this->authorizeSite($site);
        abort_unless(filled(config('maxguard.ga4.client_id')) && filled(config('maxguard.ga4.client_secret')), 422, 'Google OAuth is not configured.');
        $state = Str::random(40);
        session(['ga4_oauth_state' => $state, 'ga4_website_id' => $site->id]);
        $query = http_build_query([
            'client_id' => config('maxguard.ga4.client_id'),
            'redirect_uri' => config('maxguard.ga4.redirect_uri') ?: route('ga4.callback'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    /** Validate OAuth state, exchange the code and persist encrypted tokens. */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless(hash_equals((string) session('ga4_oauth_state'), (string) $request->query('state')), 403, 'Invalid OAuth state.');
        $site = Website::query()->findOrFail((int) session('ga4_website_id'));
        $this->authorizeSite($site);
        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $request->query('code'),
            'client_id' => config('maxguard.ga4.client_id'),
            'client_secret' => config('maxguard.ga4.client_secret'),
            'redirect_uri' => config('maxguard.ga4.redirect_uri') ?: route('ga4.callback'),
            'grant_type' => 'authorization_code',
        ]);
        abort_unless($response->successful(), 422, 'Google rejected the OAuth token exchange.');
        $site->ga4Connection()->updateOrCreate([], [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'token_expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600) - 60)),
        ]);
        session()->forget(['ga4_oauth_state', 'ga4_website_id']);

        return redirect()->route('sites.show', $site)->with('status', 'GA4 connected. Enter the numeric property ID to sync traffic.');
    }

    /** Save the numeric GA4 property selected by the site owner. */
    public function update(Request $request, Website $site): RedirectResponse
    {
        $this->authorizeSite($site);
        $data = $request->validate(['property_id' => ['required', 'digits_between:1,20']]);
        $site->ga4Connection()->updateOrCreate([], ['property_id' => $data['property_id']]);

        return back()->with('status', 'GA4 property saved.');
    }

    /** Pull and persist the latest seven-day URL traffic report on demand. */
    public function sync(Website $site, Ga4TrafficService $traffic): RedirectResponse
    {
        $this->authorizeSite($site);
        $rows = $traffic->sync($site);

        return back()->with('status', count($rows).' GA4 URL rows synced for the last 7 days.');
    }

    /** Enforce website ownership for every OAuth and GA4 mutation. */
    private function authorizeSite(Website $site): void
    {
        abort_if(auth()->id() !== null && $site->user_id !== auth()->id(), 403);
    }
}
