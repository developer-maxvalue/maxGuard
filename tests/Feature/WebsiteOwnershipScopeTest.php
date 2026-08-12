<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WebsiteOwnershipScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_different_users_can_register_the_same_domain(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        Website::query()->create([
            'user_id' => $firstUser->id,
            'name' => 'First account site',
            'slug' => 'first-example-com',
            'domain' => 'example.com',
            'start_url' => 'https://example.com/',
        ]);

        $this->actingAs($secondUser)
            ->post(route('sites.store'), [
                'name' => 'Second account site',
                'start_url' => 'https://example.com/',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Website::query()->where('domain', 'example.com')->count());
    }

    public function test_one_user_cannot_register_the_same_domain_twice(): void
    {
        $user = User::factory()->create();
        Website::query()->create([
            'user_id' => $user->id,
            'name' => 'Existing site',
            'slug' => 'existing-example-com',
            'domain' => 'example.com',
            'start_url' => 'https://example.com/',
        ]);

        $this->actingAs($user)
            ->from(route('sites.index'))
            ->post(route('sites.store'), [
                'name' => 'Duplicate site',
                'start_url' => 'https://example.com/',
            ])
            ->assertRedirect(route('sites.index'))
            ->assertSessionHasErrors('start_url');

        $this->assertSame(1, Website::query()->where('user_id', $user->id)->where('domain', 'example.com')->count());
    }
}
