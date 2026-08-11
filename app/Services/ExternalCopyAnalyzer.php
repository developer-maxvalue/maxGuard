<?php

namespace App\Services;

use App\Data\DetectorResult;
use App\Data\PageDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ExternalCopyAnalyzer
{
    /** @var array<string, mixed> */
    private array $lastTrace = [];

    public function __construct(
        private SafeHttpClient $http,
        private PageInspector $inspector,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) config('maxguard.external_copy.enabled')
            && filled(config('maxguard.external_copy.api_key'));
    }

    /** @return array<string, mixed> */
    public function lastTrace(): array
    {
        return $this->lastTrace;
    }

    /** @return list<DetectorResult> */
    public function analyze(PageDocument $page): array
    {
        $minimumWords = max(50, (int) config('maxguard.external_copy.minimum_words', 250));
        $this->lastTrace = ['configured' => $this->isConfigured(), 'attempted' => false];
        if (! $this->isConfigured() || $page->wordCount < $minimumWords) {
            $this->lastTrace['skipped_reason'] = ! $this->isConfigured() ? 'not_configured' : 'page_too_short';

            return [];
        }

        try {
            $phrases = $this->searchPhrases($page->text);
            $links = [];
            $requestIds = [];
            $creditsUsed = 0;
            $host = strtolower((string) parse_url($page->url, PHP_URL_HOST));
            foreach ($phrases as $phrase) {
                $response = Http::acceptJson()->asJson()
                    ->withToken((string) config('maxguard.external_copy.api_key'))
                    ->connectTimeout(8)
                    ->timeout(max(10, (int) config('maxguard.external_copy.timeout_seconds', 20)))
                    ->retry(1, 500, fn (Throwable $error): bool => $error instanceof ConnectionException, false)
                    ->post((string) config('maxguard.external_copy.endpoint'), [
                        'query' => '"'.$phrase.'"',
                        'search_depth' => 'basic',
                        'topic' => 'general',
                        'max_results' => max(1, min(20, (int) config('maxguard.external_copy.candidates_per_query', 5))),
                        'include_answer' => false,
                        'include_raw_content' => false,
                        'include_images' => false,
                        'exclude_domains' => [$host],
                    ]);
                $this->lastTrace['attempted'] = true;
                if (! $response->successful()) {
                    throw new \RuntimeException('Tavily Search API returned HTTP '.$response->status().'.');
                }
                if (is_string($response->json('request_id'))) {
                    $requestIds[] = $response->json('request_id');
                }
                $creditsUsed += (int) $response->json('usage.credits', 0);
                foreach ((array) $response->json('results', []) as $item) {
                    $url = is_array($item) ? ($item['url'] ?? null) : null;
                    if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && ! $this->sameSite($host, $url)) {
                        $links[$url] = true;
                    }
                }
            }

            $best = null;
            $errors = 0;
            foreach (array_slice(array_keys($links), 0, 10) as $candidateUrl) {
                try {
                    $response = $this->http->get($candidateUrl);
                    if ($response->status >= 400 || ! str_contains(strtolower(implode(' ', (array) ($response->headers['Content-Type'] ?? $response->headers['content-type'] ?? []))), 'html')) {
                        continue;
                    }
                    $candidate = $this->inspector->inspect($response);
                    $comparison = $this->compareTexts($page->text, $candidate->text);
                    if ($best === null || $comparison['similarity'] > $best['similarity']) {
                        $best = $comparison + ['url' => $candidate->url, 'title' => $candidate->title];
                    }
                } catch (Throwable) {
                    $errors++;
                }
            }

            $this->lastTrace += [
                'queries' => count($phrases),
                'request_ids' => $requestIds,
                'credits_used' => $creditsUsed,
                'candidates_found' => count($links),
                'candidate_fetch_errors' => $errors,
                'best_similarity' => $best['similarity'] ?? 0,
            ];
            $threshold = max(0.05, min(1.0, (float) config('maxguard.external_copy.review_threshold', 0.35)));
            if ($best === null || $best['similarity'] < $threshold) {
                return [];
            }

            $high = max($threshold, min(1.0, (float) config('maxguard.external_copy.high_threshold', 0.65)));
            $percent = (int) round($best['similarity'] * 100);

            return [new DetectorResult(
                ruleKey: 'copyright.external-content-match',
                category: 'Copyright',
                severity: $best['similarity'] >= $high ? 'high' : 'review',
                confidence: min(96, max(60, $percent)),
                title: 'Nội dung tương đồng đáng kể với website khác',
                summary: "Khoảng {$percent}% shingle nội dung của trang trùng với một trang ngoài website. Đây là tín hiệu cần xác minh nguồn và ngày xuất bản, không phải kết luận vi phạm bản quyền.",
                policyReference: 'Google Publisher Policies — replicated content and intellectual property abuse',
                signals: [
                    'analysis_source' => 'tavily_external_copy_search',
                    'matched_url' => $best['url'],
                    'matched_title' => $best['title'],
                    'similarity' => $percent,
                    'method' => '5-word shingle containment after exact-phrase search',
                    'matching_phrases' => $best['matching_phrases'],
                    'queries' => $phrases,
                ],
                remediation: ['Xác minh trang nào xuất bản trước và lưu bằng chứng quyền sở hữu.', 'Bổ sung bình luận, phân tích hoặc giá trị biên tập nguyên bản nếu đang tổng hợp nội dung.', 'Gỡ hoặc viết lại phần sao chép khi không có giấy phép.'],
                fingerprintSalt: hash('sha256', (string) $best['url']),
            )];
        } catch (Throwable $exception) {
            $this->lastTrace['error'] = mb_substr($exception->getMessage(), 0, 1000);

            return [];
        }
    }

    /** @return array{similarity: float, matching_phrases: list<string>} */
    public function compareTexts(string $source, string $candidate): array
    {
        $sourceTokens = $this->tokens($source);
        $candidateTokens = $this->tokens($candidate);
        $sourceShingles = $this->shingles($sourceTokens, 5);
        $candidateShingles = $this->shingles($candidateTokens, 5);
        if ($sourceShingles === [] || $candidateShingles === []) {
            return ['similarity' => 0.0, 'matching_phrases' => []];
        }

        $intersection = array_intersect_key($sourceShingles, $candidateShingles);
        $similarity = count($intersection) / max(1, count($sourceShingles));
        $phrases = [];
        for ($index = 0, $count = count($sourceTokens) - 12; $index <= $count && count($phrases) < 3; $index += 6) {
            $window = array_slice($sourceTokens, $index, 12);
            $windowShingles = $this->shingles($window, 5);
            if ($windowShingles !== [] && count(array_intersect_key($windowShingles, $candidateShingles)) === count($windowShingles)) {
                $phrases[] = implode(' ', $window);
            }
        }

        return ['similarity' => round($similarity, 4), 'matching_phrases' => $phrases];
    }

    /** @return list<string> */
    private function searchPhrases(string $text): array
    {
        $tokens = $this->tokens($text);
        $limit = max(1, min(3, (int) config('maxguard.external_copy.queries_per_page', 2)));
        $phrases = [];
        foreach ([0.20, 0.50, 0.80] as $position) {
            $offset = min(max(0, count($tokens) - 14), (int) floor(count($tokens) * $position));
            $phrase = implode(' ', array_slice($tokens, $offset, 14));
            if (mb_strlen($phrase) >= 50) {
                $phrases[$phrase] = true;
            }
            if (count($phrases) >= $limit) {
                break;
            }
        }

        return array_keys($phrases);
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $normalized = mb_strtolower($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches);

        return array_values(array_filter($matches[0] ?? [], fn (string $token): bool => mb_strlen($token) > 1));
    }

    /** @param list<string> $tokens @return array<string, true> */
    private function shingles(array $tokens, int $size): array
    {
        $result = [];
        for ($index = 0, $last = count($tokens) - $size; $index <= $last; $index++) {
            $result[hash('xxh3', implode(' ', array_slice($tokens, $index, $size)))] = true;
        }

        return $result;
    }

    private function sameSite(string $sourceHost, string $candidateUrl): bool
    {
        $candidateHost = strtolower((string) parse_url($candidateUrl, PHP_URL_HOST));

        return $candidateHost === $sourceHost
            || str_ends_with($candidateHost, '.'.$sourceHost)
            || str_ends_with($sourceHost, '.'.$candidateHost);
    }
}
