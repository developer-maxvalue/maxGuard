<?php

namespace Tests\Feature;

use App\Jobs\RunWebsiteScan;
use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WebsiteWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_add_a_website_and_queue_the_default_100_urls_immediately(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sites.store'), [
            'name' => 'Immediate scan',
            'start_url' => 'https://example.org/',
            'start_scan' => '1',
            'max_urls' => '100',
        ])->assertSessionHasNoErrors();

        $website = Website::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('scans', [
            'website_id' => $website->id,
            'status' => Scan::STATUS_QUEUED,
            'max_urls' => 100,
        ]);
        Queue::assertPushed(RunWebsiteScan::class);
    }

    public function test_scan_all_site_removes_the_url_limit(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('sites.store'), [
            'name' => 'Full website scan',
            'start_url' => 'https://example.net/',
            'start_scan' => '1',
            'max_urls' => '100',
            'scan_all_site' => '1',
        ])->assertSessionHasNoErrors();

        $website = Website::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertDatabaseHas('scans', [
            'website_id' => $website->id,
            'status' => Scan::STATUS_QUEUED,
            'max_urls' => null,
        ]);
    }
}
