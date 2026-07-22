<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartScanRequest;
use App\Models\Scan;
use App\Models\Website;
use App\Services\ScanDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ScanController extends Controller
{
    public function index(): View
    {
        $visibleScans = Scan::query()->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()));
        $running = (clone $visibleScans)->where('status', Scan::STATUS_RUNNING)->count();
        $queued = (clone $visibleScans)->where('status', Scan::STATUS_QUEUED)->count();

        return view('scans.index', [
            'sites' => Website::query()->accessibleBy(auth()->id())->orderBy('domain')->get()->map(fn (Website $website): array => [
                'domain' => $website->domain,
                'slug' => $website->slug,
            ])->all(),
            'recentScans' => Scan::query()->whereHas('website', fn ($query) => $query->accessibleBy(auth()->id()))->with('website')->latest()->limit(10)->get(),
            'scanStats' => ['running' => $running, 'queued' => $queued, 'utilization' => min(100, ($running * 35) + ($queued * 10))],
        ]);
    }

    public function store(StartScanRequest $request, ScanDispatcher $dispatcher): RedirectResponse
    {
        $data = $request->validated();
        $query = Website::query()->accessibleBy(auth()->id());
        if ($data['site'] !== 'all-sites') {
            $query->where(fn ($query) => $query->where('domain', $data['site'])->orWhere('slug', $data['site']));
        }

        $websites = $query->get();
        if ($websites->isEmpty()) {
            return back()->withErrors(['site' => 'Website not found.'])->withInput();
        }

        dd($websites);
        $queued = 0;
        foreach ($websites as $website) {
            try {
                $dispatcher->dispatch($website, $data['scan_type'], auth()->id());
                $queued++;
            } catch (\Illuminate\Validation\ValidationException) {
                // A scan is already active for this website; continue queuing the rest.
            }
        }

        return redirect()
            ->route('scans.index')
            ->with('status', $queued > 0 ? "{$queued} scan(s) queued successfully." : 'All selected websites already have an active scan.');
    }
}
