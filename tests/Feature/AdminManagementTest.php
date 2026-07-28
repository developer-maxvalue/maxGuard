<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_system_administration(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)->get(route('admin.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.index'))->assertOk();
    }

    public function test_admin_can_see_and_open_another_users_website(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $website = $this->website($owner, 'admin-visible.example');

        $this->actingAs($admin)
            ->get(route('sites.index'))
            ->assertOk()
            ->assertSee($website->domain);

        $this->actingAs($admin)
            ->get(route('sites.show', $website))
            ->assertOk();
    }

    public function test_owner_can_delete_website_without_active_scan(): void
    {
        $owner = User::factory()->create();
        $website = $this->website($owner, 'delete-me.example');

        $this->actingAs($owner)
            ->delete(route('sites.destroy', $website))
            ->assertRedirect(route('sites.index'));

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
    }

    public function test_website_with_active_scan_cannot_be_deleted(): void
    {
        $owner = User::factory()->create();
        $website = $this->website($owner, 'busy.example');
        Scan::query()->create([
            'website_id' => $website->id,
            'requested_by' => $owner->id,
            'status' => Scan::STATUS_RUNNING,
        ]);

        $this->actingAs($owner)
            ->from(route('sites.show', $website))
            ->delete(route('sites.destroy', $website))
            ->assertRedirect(route('sites.show', $website))
            ->assertSessionHasErrors('site');

        $this->assertDatabaseHas('websites', ['id' => $website->id]);
    }

    private function website(User $owner, string $domain): Website
    {
        return Website::query()->create([
            'user_id' => $owner->id,
            'name' => $domain,
            'slug' => str_replace('.', '-', $domain),
            'domain' => $domain,
            'start_url' => "https://{$domain}/",
        ]);
    }
}
