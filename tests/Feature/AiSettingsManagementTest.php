<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiSettingsManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_can_manage_ai_settings(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.ai-settings.index'))->assertForbidden();
        $this->actingAs($user)->patch(route('admin.ai-settings.update'), $this->payload())->assertForbidden();
    }

    public function test_admin_can_save_database_settings_with_an_encrypted_api_key(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.ai-settings.update'), $this->payload([
                'provider' => 'openai_compatible',
                'base_url' => 'https://ai.example.test/v1',
                'api_key' => 'secret-api-key',
                'model' => 'custom-model',
            ]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $setting = AiSetting::query()->firstOrFail();
        $this->assertSame('secret-api-key', $setting->api_key);
        $this->assertNotSame('secret-api-key', $setting->getRawOriginal('api_key'));
        $this->assertSame('openai_compatible', config('maxguard.ai.provider'));
        $this->assertSame('custom-model', config('maxguard.ai.model'));

        $this->actingAs($admin)
            ->get(route('admin.ai-settings.index'))
            ->assertOk()
            ->assertSee('Tương thích OpenAI')
            ->assertDontSee('secret-api-key');
    }

    public function test_admin_can_save_and_test_an_ollama_connection(): void
    {
        Http::fake(['http://127.0.0.1:11434/api/tags' => Http::response(['models' => []])]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.ai-settings.update'), $this->payload([
                'provider' => 'ollama',
                'base_url' => 'http://127.0.0.1:11434',
                'model' => 'qwen3:8b',
                'test_connection' => 'page',
            ]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Kết nối thành công đến Ollama cho kiểm tra từng URL.');

        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:11434/api/tags');
    }

    public function test_admin_can_save_and_test_an_anthropic_connection(): void
    {
        Http::fake(['https://api.anthropic.test/v1/models' => Http::response(['data' => []])]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.ai-settings.update'), $this->payload([
                'provider' => 'anthropic',
                'base_url' => 'https://api.anthropic.test/v1',
                'api_key' => 'anthropic-test-key',
                'model' => 'claude-sonnet-5',
                'test_connection' => 'page',
            ]))
            ->assertRedirect()
            ->assertSessionHas('status', 'Kết nối thành công đến Claude/Anthropic cho kiểm tra từng URL.');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.anthropic.test/v1/models'
            && $request->hasHeader('x-api-key', 'anthropic-test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function test_admin_can_configure_gemini_for_pages_and_claude_for_realtime_review(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->patch(route('admin.ai-settings.update'), $this->payload([
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash',
                'review_enabled' => '1',
                'review_provider' => 'anthropic',
                'review_base_url' => 'https://api.anthropic.test/v1',
                'review_api_key' => 'review-secret-key',
                'review_model' => 'claude-sonnet-4-6',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $setting = AiSetting::query()->firstOrFail();
        $this->assertSame('gemini', $setting->provider);
        $this->assertSame('claude-sonnet-4-6', $setting->review_model);
        $this->assertSame('review-secret-key', $setting->review_api_key);
        $this->assertNotSame('review-secret-key', $setting->getRawOriginal('review_api_key'));
        $this->assertSame('gemini-2.5-flash', config('maxguard.ai.model'));
        $this->assertSame('claude-sonnet-4-6', config('maxguard.review_ai.model'));
        $this->assertTrue(app(\App\Services\AiConfiguration::class)->isReady());
        $this->assertTrue(app(\App\Services\AiConfiguration::class)->isWebReviewReady());

        $this->actingAs($admin)
            ->get(route('admin.ai-settings.index'))
            ->assertOk()
            ->assertSee('AI kiểm tra từng URL trong crawler')
            ->assertSee('AI đánh giá tổng quan realtime')
            ->assertSee('name="review_provider"', false)
            ->assertSee('name="review_model"', false)
            ->assertSee('value="claude-sonnet-4-6"', false)
            ->assertDontSee('review-secret-key');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'enabled' => '1',
            'provider' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'api_key' => 'test-key',
            'model' => 'gemini-2.5-flash',
            'review_provider' => 'anthropic',
            'review_base_url' => 'https://api.anthropic.com/v1',
            'review_model' => 'claude-sonnet-4-6',
            'output_language' => 'Vietnamese',
            'max_pages_per_scan' => 100,
            'min_confidence' => 70,
            'max_input_chars' => 12000,
            'max_output_tokens' => 1800,
            'connect_timeout_seconds' => 10,
            'timeout_seconds' => 90,
        ], $overrides);
    }
}
