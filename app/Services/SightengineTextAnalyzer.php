<?php

namespace App\Services;

use App\Data\DetectorResult;
use App\Data\PageDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Adapter for Sightengine text moderation.
 *
 * The adapter deliberately returns normal DetectorResult objects, so the rest
 * of the scan pipeline does not need to know which external vendor was used.
 */
final class SightengineTextAnalyzer
{
    /** @var array<string, mixed> */
    private array $lastTrace = [];

    /**
     * Return sanitized metadata from the most recent request for URL telemetry.
     *
     * @return array<string, mixed>
     */
    public function lastTrace(): array
    {
        return $this->lastTrace;
    }

    /** Determine whether all required Sightengine environment values exist. */
    public function isConfigured(): bool
    {
        return (bool) config('maxguard.sightengine.enabled')
            && filled(config('maxguard.sightengine.api_user'))
            && filled(config('maxguard.sightengine.api_secret'));
    }

    /** @return list<DetectorResult> */
    public function analyze(PageDocument $page): array
    {
        $this->lastTrace = ['configured' => $this->isConfigured(), 'attempted' => false];
        if (! $this->isConfigured() || trim($page->text) === '') {
            return [];
        }
        if ($reason = Cache::get('maxguard:circuit:sightengine')) {
            $this->lastTrace['skipped_reason'] = (string) $reason;

            return [];
        }

        try {
            $this->lastTrace['attempted'] = true;
            $request = Http::acceptJson()
                ->connectTimeout((int) config('maxguard.sightengine.connect_timeout_seconds', 10))
                ->timeout((int) config('maxguard.sightengine.timeout_seconds', 45))
                ->retry(2, 600, fn (Throwable $e): bool => $e instanceof ConnectionException, false);
            foreach ([
                'text' => mb_substr($page->text, 0, (int) config('maxguard.sightengine.max_input_chars', 20_000)),
                'models' => (string) config('maxguard.sightengine.models', 'general,self-harm'),
                'api_user' => (string) config('maxguard.sightengine.api_user'),
                'api_secret' => (string) config('maxguard.sightengine.api_secret'),
                'mode' => 'ml',
                'lang' => $this->language($page->language),
            ] as $name => $contents) {
                $request = $request->attach($name, $contents);
            }
            $response = $request->post((string) config('maxguard.sightengine.endpoint'));
            $this->lastTrace = [
                'configured' => true,
                'attempted' => true,
                'http_status' => $response->status(),
                'request_id' => $response->json('request.id'),
                'api_status' => $response->json('status'),
                'classes' => $response->json('moderation_classes'),
            ];

            if (! $response->successful() || $response->json('status') !== 'success') {
                $this->lastTrace['error'] = (string) ($response->json('error.message') ?: 'Third-party API request failed.');
                if (str_contains(strtolower($this->lastTrace['error']), 'usage limit')) {
                    Cache::put('maxguard:circuit:sightengine', $this->lastTrace['error'], now()->endOfDay());
                }
                Log::warning('Sightengine rejected a moderation request.', [
                    'http_status' => $response->status(),
                    'request_id' => $response->json('request.id'),
                    'error' => $this->lastTrace['error'],
                ]);

                return [];
            }

            $results = [];
            $threshold = (float) config('maxguard.sightengine.violation_threshold', 0.55);
            foreach ((array) $response->json('moderation_classes', []) as $class => $score) {
                if ($class === 'available' || ! is_numeric($score) || (float) $score < $threshold) {
                    continue;
                }
                $value = (float) $score;
                $results[] = new DetectorResult(
                    ruleKey: 'sightengine.'.(string) $class,
                    category: 'Third-party moderation',
                    severity: $value >= 0.85 ? 'high' : 'review',
                    confidence: (int) round($value * 100),
                    title: 'Sightengine detected '.str_replace('_', ' ', (string) $class).' content',
                    summary: 'An external text moderation model scored this content at '.number_format($value * 100, 1).'%. A human should verify the context.',
                    policyReference: 'Sightengine text moderation: '.(string) $class,
                    signals: [
                        'analysis_source' => 'sightengine',
                        'class' => (string) $class,
                        'score' => $value,
                        'request_id' => $response->json('request.id'),
                    ],
                    remediation: ['Review the quoted page in context.', 'Edit or remove violating language, then force a new scan.'],
                );
            }

            return $results;
        } catch (Throwable $exception) {
            $this->lastTrace = [
                'configured' => true,
                'attempted' => true,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ];
            Log::warning('Sightengine moderation request failed.', [
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            return [];
        }
    }

    /** Map the page language to a language supported by Sightengine text API. */
    private function language(?string $language): string
    {
        $code = strtolower(substr((string) $language, 0, 2));

        return in_array($code, ['en', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl', 'ru', 'tr'], true) ? $code : 'en';
    }
}
