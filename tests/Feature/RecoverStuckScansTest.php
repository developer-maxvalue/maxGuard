<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecoverStuckScansTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_cancels_a_stale_scan_and_releases_the_website(): void
    {
        $website = Website::query()->create([
            'name' => 'Stuck Queue Example',
            'slug' => 'stuck-queue-example',
            'domain' => 'stuck-queue.example',
            'start_url' => 'https://stuck-queue.example/',
            'status' => 'scanning',
        ]);
        $scan = $website->scans()->create([
            'type' => 'full',
            'status' => Scan::STATUS_QUEUED,
        ]);
        Scan::query()->whereKey($scan->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('maxguard:recover-stuck-scans', ['--older-than' => 30])
            ->assertSuccessful();

        $this->assertDatabaseHas('scans', ['id' => $scan->id, 'status' => Scan::STATUS_CANCELLED]);
        $this->assertDatabaseHas('websites', ['id' => $website->id, 'status' => 'pending']);
    }
}
