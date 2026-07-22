<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_administrator_that_can_authenticate(): void
    {
        $this->artisan('maxguard:create-admin', [
            '--name' => 'MaxGuard Admin',
            '--email' => 'admin@example.com',
            '--password' => 'a-secure-password',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->firstOrFail();

        $this->assertSame('MaxGuard Admin', $user->name);
        $this->assertTrue(Hash::check('a-secure-password', $user->password));
    }
}
