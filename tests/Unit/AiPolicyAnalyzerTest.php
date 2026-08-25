<?php

namespace Tests\Unit;

use App\Data\PageDocument;
use App\Services\AiPolicyAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AiPolicyAnalyzerTest extends TestCase
{
    public function test_it_uses_responses_api_structured_output_and_returns_ai_findings(): void
    {
        config()->set('maxguard.ai.enabled', true);
        config()->set('maxguard.ai.provider', 'openai');
        config()->set('maxguard.ai.api_key', 'test-key');
        config()->set('maxguard.ai.base_url', 'https://api.openai.com/v1');
        config()->set('maxguard.ai.model', 'gpt-5.6-terra');
        config()->set('maxguard.ai.min_confidence', 70);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_test',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'findings' => [[
                                'policy_code' => 'deceptive_or_misleading',
                                'severity' => 'high',
                                'confidence' => 91,
                                'title' => 'Misleading claim needs review',
                                'summary' => 'The page presents an unsupported guarantee.',
                                'evidence' => ['Guaranteed result without qualification'],
                                'remediation' => ['Remove the guarantee or substantiate and qualify it.'],
                            ]],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
                'usage' => ['input_tokens' => 300, 'output_tokens' => 120],
            ]),
        ]);

        $outcome = app(AiPolicyAnalyzer::class)->analyze($this->page());

        $this->assertTrue($outcome->attempted);
        $this->assertNull($outcome->error);
        $this->assertCount(1, $outcome->findings);
        $this->assertSame('ai.deceptive_or_misleading', $outcome->findings[0]->ruleKey);
        $this->assertSame('openai', $outcome->findings[0]->signals['analysis_source']);
        $this->assertSame(300, $outcome->inputTokens);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request['store'] === false
                && $request['model'] === 'gpt-5.6-terra'
                && data_get($request->data(), 'text.format.type') === 'json_schema'
                && data_get($request->data(), 'text.format.strict') === true;
        });
    }

    private function page(): PageDocument
    {
        return new PageDocument(
            url: 'https://example.com/article',
            statusCode: 200,
            html: '<html><body><h1>Claim</h1></body></html>',
            text: 'This product guarantees a result for everyone without exception.',
            title: 'Guaranteed result',
            canonicalUrl: null,
            language: 'en',
            wordCount: 220,
            adCount: 2,
            h1Count: 1,
            links: [],
            images: [],
        );
    }
}
