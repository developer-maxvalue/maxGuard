<?php

namespace Tests\Unit;

use App\Data\CrawlResponse;
use App\Services\ExternalCopyAnalyzer;
use App\Services\PageInspector;
use App\Services\SafeHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ExternalCopyAnalyzerTest extends TestCase
{
    public function test_it_scores_substantial_external_overlap_and_returns_matching_phrases(): void
    {
        $shared = implode(' ', array_map(
            fn (int $number): string => 'originalword'.$number,
            range(1, 120),
        ));
        $source = 'publisher introduction '.$shared.' publisher conclusion';
        $candidate = 'different opening '.$shared.' different closing';

        $result = app(ExternalCopyAnalyzer::class)->compareTexts($source, $candidate);

        $this->assertGreaterThan(0.90, $result['similarity']);
        $this->assertNotEmpty($result['matching_phrases']);
    }

    public function test_it_does_not_flag_unrelated_content(): void
    {
        $source = implode(' ', array_map(fn (int $number): string => 'alpha'.$number, range(1, 80)));
        $candidate = implode(' ', array_map(fn (int $number): string => 'beta'.$number, range(1, 80)));

        $result = app(ExternalCopyAnalyzer::class)->compareTexts($source, $candidate);

        $this->assertSame(0.0, $result['similarity']);
        $this->assertSame([], $result['matching_phrases']);
    }

    public function test_it_searches_fetches_and_returns_an_external_copy_finding(): void
    {
        config()->set('maxguard.external_copy', [
            ...config('maxguard.external_copy'),
            'enabled' => true,
            'api_key' => 'test-key',
            'minimum_words' => 50,
            'queries_per_page' => 1,
            'review_threshold' => 0.35,
            'high_threshold' => 0.65,
        ]);
        Http::fake([
            'https://api.tavily.com/search' => Http::response([
                'request_id' => 'tavily-request-1',
                'usage' => ['credits' => 1],
                'results' => [['url' => 'https://copy.example.net/article']],
            ]),
        ]);
        $text = implode(' ', array_map(fn (int $number): string => 'distinctive'.$number, range(1, 160)));
        $source = new CrawlResponse('https://publisher.example/article', 200, '<html><body><article>'.$text.'</article></body></html>', [
            'Content-Type' => ['text/html'],
        ]);
        $candidate = new CrawlResponse('https://copy.example.net/article', 200, '<html><body><article>'.$text.'</article></body></html>', [
            'Content-Type' => ['text/html'],
        ]);
        $inspector = new PageInspector;
        $analyzer = new ExternalCopyAnalyzer(new ExternalCopyFakeHttpClient($candidate), $inspector);

        $results = $analyzer->analyze($inspector->inspect($source));

        $this->assertCount(1, $results);
        $this->assertSame('copyright.external-content-match', $results[0]->ruleKey);
        $this->assertSame('https://copy.example.net/article', $results[0]->signals['matched_url']);
        $this->assertSame(100, $results[0]->signals['similarity']);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.tavily.com/search'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['search_depth'] === 'basic'
            && $request['exclude_domains'] === ['publisher.example']);
    }
}

final class ExternalCopyFakeHttpClient extends SafeHttpClient
{
    public function __construct(private CrawlResponse $response) {}

    public function get(string $url, string $accept = 'text/html,application/xhtml+xml'): CrawlResponse
    {
        return $this->response;
    }
}
