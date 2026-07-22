<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OwnerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_cannot_open_or_export_another_users_website(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $website = Website::query()->create([
            'user_id' => $owner->id,
            'name' => 'Private Publisher',
            'slug' => 'private-publisher-example',
            'domain' => 'private-publisher.example',
            'start_url' => 'https://private-publisher.example/',
        ]);

        $this->actingAs($otherUser)
            ->get(route('sites.show', $website))
            ->assertForbidden();

        $this->actingAs($otherUser)
            ->get(route('sites.index'))
            ->assertOk()
            ->assertDontSee($website->domain);

        $this->actingAs($otherUser)
            ->get(route('sites.index', ['export' => 'csv']))
            ->assertDownload();
    }

    public function test_owner_can_open_their_website(): void
    {
        $owner = User::factory()->create();
        $website = Website::query()->create([
            'user_id' => $owner->id,
            'name' => 'Owned Publisher',
            'slug' => 'owned-publisher-example',
            'domain' => 'owned-publisher.example',
            'start_url' => 'https://owned-publisher.example/',
        ]);

        $this->actingAs($owner)
            ->get(route('sites.show', $website))
            ->assertOk();
    }
}
