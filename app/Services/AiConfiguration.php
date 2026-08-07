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
        ]);

        return $values;
    }

    public function isReady(): bool
    {
        $values = $this->apply();
        $keyRequired = in_array($values['provider'], ['gemini', 'openai'], true);

        return (bool) $values['enabled']
            && filled($values['base_url'])
            && filled($values['model'])
            && (! $keyRequired || filled($values['api_key']));
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

        return $values;
    }

    public static function defaultBaseUrl(string $provider): string
    {
        return match ($provider) {
            'ollama' => 'http://127.0.0.1:11434',
            'openai_compatible', 'openai' => 'https://api.openai.com/v1',
            default => 'https://generativelanguage.googleapis.com/v1beta',
        };
    }

    public static function defaultModel(string $provider): string
    {
        return match ($provider) {
            'ollama' => 'qwen3:8b',
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
}
