<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AiConfiguration
{
    /** @return array<string, mixed> */
    public function apply(): array
    {
        $values = $this->legacyValues();
        $reviewValues = $this->legacyReviewValues();

        try {
            if (Schema::hasTable('ai_settings')) {
                $setting = AiSetting::query()->latest('id')->first();
                if ($setting !== null) {
                    $values = [
                        'enabled' => $setting->enabled,
                        'provider' => $setting->provider,
                        'base_url' => rtrim($setting->base_url, '/'),
                        'api_key' => $setting->api_key,
                        'model' => $setting->model,
                        'output_language' => $setting->output_language,
                        'max_pages_per_scan' => $setting->max_pages_per_scan,
                        'min_confidence' => $setting->min_confidence,
                        'max_input_chars' => $setting->max_input_chars,
                        'max_output_tokens' => $setting->max_output_tokens,
                        'connect_timeout_seconds' => $setting->connect_timeout_seconds,
                        'timeout_seconds' => $setting->timeout_seconds,
                        'source' => 'database',
                    ];
                    if (Schema::hasColumn('ai_settings', 'review_enabled')) {
                        $reviewValues = [
                            'enabled' => $setting->review_enabled,
                            'provider' => $setting->review_provider ?: 'anthropic',
                            'base_url' => rtrim($setting->review_base_url ?: self::defaultBaseUrl('anthropic'), '/'),
                            'api_key' => $setting->review_api_key,
                            'model' => $setting->review_model ?: self::defaultModel('anthropic'),
                            'connect_timeout_seconds' => (int) config('maxguard.review_ai.connect_timeout_seconds', 10),
                            'timeout_seconds' => (int) config('maxguard.review_ai.timeout_seconds', 300),
                            'max_output_tokens' => (int) config('maxguard.review_ai.max_output_tokens', 8192),
                            'source' => 'database',
                        ];
                    } elseif ($setting->provider === 'anthropic') {
                        $reviewValues = [
                            'enabled' => $setting->enabled,
                            'provider' => 'anthropic',
                            'base_url' => rtrim($setting->base_url, '/'),
                            'api_key' => $setting->api_key,
                            'model' => $setting->model,
                            'connect_timeout_seconds' => $setting->connect_timeout_seconds,
                            'timeout_seconds' => max(300, $setting->timeout_seconds),
                            'max_output_tokens' => max(2000, $setting->max_output_tokens),
                            'source' => 'database_legacy',
                        ];
                    }
                }
            }
        } catch (Throwable) {
            // Commands such as migrate must keep working before the settings table exists.
        }

        config()->set([
            'maxguard.ai.enabled' => (bool) $values['enabled'],
            'maxguard.ai.provider' => (string) $values['provider'],
            'maxguard.ai.api_key' => $values['api_key'],
            'maxguard.ai.base_url' => (string) $values['base_url'],
            'maxguard.ai.gemini_base_url' => (string) $values['base_url'],
            'maxguard.ai.model' => (string) $values['model'],
            'maxguard.ai.output_language' => (string) $values['output_language'],
            'maxguard.ai.max_pages_per_scan' => (int) $values['max_pages_per_scan'],
            'maxguard.ai.min_confidence' => (int) $values['min_confidence'],
            'maxguard.ai.max_input_chars' => (int) $values['max_input_chars'],
            'maxguard.ai.max_output_tokens' => (int) $values['max_output_tokens'],
            'maxguard.ai.connect_timeout_seconds' => (int) $values['connect_timeout_seconds'],
            'maxguard.ai.timeout_seconds' => (int) $values['timeout_seconds'],
            'maxguard.review_ai.enabled' => (bool) $reviewValues['enabled'],
            'maxguard.review_ai.provider' => (string) $reviewValues['provider'],
            'maxguard.review_ai.api_key' => $reviewValues['api_key'],
            'maxguard.review_ai.base_url' => (string) $reviewValues['base_url'],
            'maxguard.review_ai.model' => (string) $reviewValues['model'],
            'maxguard.review_ai.connect_timeout_seconds' => (int) $reviewValues['connect_timeout_seconds'],
            'maxguard.review_ai.timeout_seconds' => (int) $reviewValues['timeout_seconds'],
            'maxguard.review_ai.max_output_tokens' => (int) $reviewValues['max_output_tokens'],
            'maxguard.web_review.enabled' => (bool) $reviewValues['enabled'],
        ]);

        $values['review'] = $reviewValues;

        return $values;
    }

    public function isReady(): bool
    {
        $values = $this->apply();
        $keyRequired = in_array($values['provider'], ['gemini', 'anthropic', 'openai', 'openai_compatible'], true);

        return (bool) $values['enabled']
            && filled($values['base_url'])
            && filled($values['model'])
            && (! $keyRequired || filled($values['api_key']));
    }

    public function isWebReviewReady(): bool
    {
        $values = $this->apply()['review'];
        $keyRequired = in_array($values['provider'], ['gemini', 'anthropic', 'openai', 'openai_compatible'], true);

        return (bool) $values['enabled']
            && $values['provider'] === 'anthropic'
            && filled($values['base_url'])
            && filled($values['model'])
            && (! $keyRequired || filled($values['api_key']));
    }

    public function anyReady(): bool
    {
        return $this->isReady() || $this->isWebReviewReady();
    }

    /** @return array<string, mixed> */
    public function formValues(): array
    {
        $values = $this->apply();
        if ($values['provider'] === 'openai') {
            $values['provider'] = 'openai_compatible';
        }
        $values['has_api_key'] = filled($values['api_key']);
        unset($values['api_key']);
        $values['review']['has_api_key'] = filled($values['review']['api_key']);
        unset($values['review']['api_key']);

        return $values;
    }

    public static function defaultBaseUrl(string $provider): string
    {
        return match ($provider) {
            'ollama' => 'http://127.0.0.1:11434',
            'anthropic' => 'https://api.anthropic.com/v1',
            'openai_compatible', 'openai' => 'https://api.openai.com/v1',
            default => 'https://generativelanguage.googleapis.com/v1beta',
        };
    }

    public static function defaultModel(string $provider): string
    {
        return match ($provider) {
            'ollama' => 'qwen3:8b',
            'anthropic' => 'claude-sonnet-5',
            'openai_compatible', 'openai' => 'gpt-4.1-mini',
            default => 'gemini-2.5-flash',
        };
    }

    /** @return array<string, mixed> */
    private function legacyValues(): array
    {
        $provider = (string) config('maxguard.ai.provider', 'gemini');
        $model = (string) config('maxguard.ai.model', self::defaultModel($provider));
        $baseUrl = $provider === 'gemini' && ! str_starts_with(strtolower($model), 'gpt-')
            ? (string) config('maxguard.ai.gemini_base_url', self::defaultBaseUrl('gemini'))
            : (string) config('maxguard.ai.base_url', self::defaultBaseUrl($provider));

        return [
            'enabled' => (bool) config('maxguard.ai.enabled', false),
            'provider' => $provider,
            'base_url' => rtrim($baseUrl, '/'),
            'api_key' => config('maxguard.ai.api_key'),
            'model' => $model,
            'output_language' => (string) config('maxguard.ai.output_language', 'Vietnamese'),
            'max_pages_per_scan' => (int) config('maxguard.ai.max_pages_per_scan', 100),
            'min_confidence' => (int) config('maxguard.ai.min_confidence', 70),
            'max_input_chars' => (int) config('maxguard.ai.max_input_chars', 12000),
            'max_output_tokens' => (int) config('maxguard.ai.max_output_tokens', 1800),
            'connect_timeout_seconds' => (int) config('maxguard.ai.connect_timeout_seconds', 10),
            'timeout_seconds' => (int) config('maxguard.ai.timeout_seconds', 90),
            'source' => 'environment',
        ];
    }

    /** @return array<string, mixed> */
    private function legacyReviewValues(): array
    {
        $provider = (string) config('maxguard.review_ai.provider', 'anthropic');

        return [
            'enabled' => (bool) config('maxguard.review_ai.enabled', false),
            'provider' => $provider,
            'base_url' => rtrim((string) config('maxguard.review_ai.base_url', self::defaultBaseUrl($provider)), '/'),
            'api_key' => config('maxguard.review_ai.api_key'),
            'model' => (string) config('maxguard.review_ai.model', self::defaultModel($provider)),
            'connect_timeout_seconds' => (int) config('maxguard.review_ai.connect_timeout_seconds', 10),
            'timeout_seconds' => (int) config('maxguard.review_ai.timeout_seconds', 300),
            'max_output_tokens' => (int) config('maxguard.review_ai.max_output_tokens', 8192),
            'source' => 'environment',
        ];
    }
}
