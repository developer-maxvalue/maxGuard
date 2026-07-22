<?php

namespace Tests\Feature;

use App\Jobs\RunWebsiteScan;
use App\Models\Scan;
use App\Models\Website;
use App\Services\ScanDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ScanDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_and_queues_a_scan(): void
    {
        Queue::fake();
        $website = Website::query()->create([
            'name' => 'Example',
            'slug' => 'example-com',
            'domain' => 'example.com',
            'start_url' => 'https://example.com/',
            'ownership_verified_at' => now(),
        ]);

        $scan = app(ScanDispatcher::class)->dispatch($website, 'full');

        $this->assertSame(Scan::STATUS_QUEUED, $scan->status);
        $this->assertDatabaseHas('scans', ['website_id' => $website->id, 'status' => Scan::STATUS_QUEUED]);
        Queue::assertPushed(RunWebsiteScan::class, fn (RunWebsiteScan $job): bool => $job->scan->is($scan));
    }
}
