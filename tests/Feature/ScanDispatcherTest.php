<?php

namespace Tests\Feature;

use App\Jobs\RunWebsiteScan;
use App\Models\Scan;
use App\Models\Website;
use App\Services\ScanDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ScanDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_and_queues_a_scan_without_an_ownership_file(): void
    {
        Queue::fake();
        $website = Website::query()->create([
            'name' => 'Example',
            'slug' => 'example-com',
            'domain' => 'example.com',
            'start_url' => 'https://example.com/',
        ]);

        $scan = app(ScanDispatcher::class)->dispatch($website, 'full', null, 250, false, true);

        $this->assertSame(Scan::STATUS_QUEUED, $scan->status);
        $this->assertDatabaseHas('scans', [
            'website_id' => $website->id,
            'status' => Scan::STATUS_QUEUED,
            'max_urls' => 250,
            'use_ai' => false,
            'force_rescan' => true,
            'ruleset_version' => '1.4.0',
        ]);
        Queue::assertPushed(RunWebsiteScan::class, fn (RunWebsiteScan $job): bool => $job->scan->is($scan));
    }

    public function test_queue_connection_failure_does_not_leave_the_website_stuck(): void
    {
        config()->set('queue.default', 'missing-connection');
        $website = Website::query()->create([
            'name' => 'Queue Failure Example',
            'slug' => 'queue-failure-example',
            'domain' => 'queue-failure.example',
            'start_url' => 'https://queue-failure.example/',
            'status' => 'pending',
        ]);

        try {
            app(ScanDispatcher::class)->dispatch($website, 'full');
            $this->fail('Expected queue dispatch to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('queue', $exception->errors());
        }

        $this->assertDatabaseHas('websites', ['id' => $website->id, 'status' => 'pending']);
        $this->assertDatabaseHas('scans', ['website_id' => $website->id, 'status' => Scan::STATUS_FAILED]);
    }
}
