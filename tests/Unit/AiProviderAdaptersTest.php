<?php

namespace Tests\Unit;

use App\Data\PageDocument;
use App\Services\AiPolicyAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiProviderAdaptersTest extends TestCase
{
    public function test_ollama_uses_the_native_chat_endpoint_and_structured_format(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'ollama',
            'maxguard.ai.api_key' => null,
            'maxguard.ai.base_url' => 'http://127.0.0.1:11434',
            'maxguard.ai.model' => 'qwen3:8b',
            'maxguard.ai.min_confidence' => 70,
        ]);
        Http::fake([
            'http://127.0.0.1:11434/api/chat' => Http::response([
                'message' => ['content' => json_encode(['findings' => []], JSON_THROW_ON_ERROR)],
                'prompt_eval_count' => 20,
                'eval_count' => 5,
            ]),
        ]);

        $outcome = app(AiPolicyAnalyzer::class)->analyze($this->page());

        $this->assertTrue($outcome->attempted);
        $this->assertNull($outcome->error);
        $this->assertSame(20, $outcome->inputTokens);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:11434/api/chat'
            && $request['stream'] === false
            && data_get($request->data(), 'format.type') === 'object');
    }

    public function test_openai_compatible_provider_uses_chat_completions(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'openai_compatible',
            'maxguard.ai.api_key' => 'compatible-key',
            'maxguard.ai.base_url' => 'https://compatible.example/v1',
            'maxguard.ai.model' => 'local-model',
            'maxguard.ai.min_confidence' => 70,
        ]);
        Http::fake([
            'https://compatible.example/v1/chat/completions' => Http::response([
                'id' => 'chat_test',
                'choices' => [['message' => ['content' => json_encode(['findings' => []], JSON_THROW_ON_ERROR)]]],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 8],
            ]),
        ]);

        $outcome = app(AiPolicyAnalyzer::class)->analyze($this->page());

        $this->assertTrue($outcome->attempted);
        $this->assertNull($outcome->error);
        $this->assertSame(30, $outcome->inputTokens);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://compatible.example/v1/chat/completions'
            && data_get($request->data(), 'response_format.type') === 'json_object'
            && $request->hasHeader('Authorization', 'Bearer compatible-key'));
    }

    public function test_anthropic_provider_uses_messages_api_and_json_schema_output(): void
    {
        config()->set([
            'maxguard.ai.enabled' => true,
            'maxguard.ai.provider' => 'anthropic',
            'maxguard.ai.api_key' => 'anthropic-key',
            'maxguard.ai.base_url' => 'https://api.anthropic.test/v1',
            'maxguard.ai.model' => 'claude-test-model',
            'maxguard.ai.min_confidence' => 70,
            'maxguard.ai.max_output_tokens' => 5000,
            'maxguard.ai.anthropic_max_output_tokens' => 6000,
        ]);
        Http::fake([
            'https://api.anthropic.test/v1/messages' => Http::response([
                'id' => 'msg_test',
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Internal reasoning block'],
                    ['type' => 'text', 'text' => json_encode(['findings' => []], JSON_THROW_ON_ERROR)],
                ],
                'usage' => ['input_tokens' => 42, 'output_tokens' => 9],
            ]),
        ]);

        $outcome = app(AiPolicyAnalyzer::class)->analyze($this->page());

        $this->assertTrue($outcome->attempted);
        $this->assertNull($outcome->error);
        $this->assertSame(42, $outcome->inputTokens);
        $this->assertSame(9, $outcome->outputTokens);
        Http::assertSent(function (Request $request): bool {
            $schema = json_encode(data_get($request->data(), 'output_config.format.schema'));

            return $request->url() === 'https://api.anthropic.test/v1/messages'
                && $request->hasHeader('x-api-key', 'anthropic-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && data_get($request->data(), 'output_config.format.type') === 'json_schema'
                && data_get($request->data(), 'messages.0.role') === 'user'
                && $request['max_tokens'] === 6000
                && ! array_key_exists('temperature', $request->data())
                && ! str_contains((string) $schema, 'maxItems')
                && ! str_contains((string) $schema, 'minimum')
                && ! str_contains((string) $schema, 'maximum');
        });
    }

    private function page(): PageDocument
    {
        return new PageDocument(
            url: 'https://example.com/article',
            statusCode: 200,
            html: '<html><body><h1>Nội dung</h1></body></html>',
            text: 'Nội dung bài viết cần được phân tích chính sách.',
            title: 'Bài viết',
            canonicalUrl: null,
            language: 'vi',
            wordCount: 220,
            adCount: 1,
            h1Count: 1,
            links: [],
            images: [],
        );
    }
}
