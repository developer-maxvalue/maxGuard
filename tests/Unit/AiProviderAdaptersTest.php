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
