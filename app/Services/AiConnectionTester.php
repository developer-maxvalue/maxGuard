<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AiConnectionTester
{
    /** @return array{success: bool, message: string} */
    public function test(AiSetting $setting, string $role = 'page'): array
    {
        try {
            $provider = $role === 'review' ? (string) $setting->review_provider : (string) $setting->provider;
            $baseUrl = rtrim($role === 'review' ? (string) $setting->review_base_url : (string) $setting->base_url, '/');
            $apiKey = $role === 'review' ? $setting->review_api_key : $setting->api_key;
            $request = Http::acceptJson()
                ->connectTimeout(min(20, $setting->connect_timeout_seconds))
                ->timeout(min(30, $setting->timeout_seconds))
                ->retry(1, 300, fn (Throwable $error): bool => $error instanceof ConnectionException, false);

            if ($provider === 'gemini') {
                if (blank($apiKey)) {
                    return ['success' => false, 'message' => 'Gemini yêu cầu API key.'];
                }
                $response = $request->get($baseUrl.'/models', ['key' => $apiKey]);
            } elseif ($provider === 'anthropic') {
                if (blank($apiKey)) {
                    return ['success' => false, 'message' => 'Claude/Anthropic yêu cầu API key.'];
                }
                $response = $request
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => '2023-06-01',
                    ])
                    ->get($baseUrl.'/models');
            } elseif ($provider === 'ollama') {
                if (filled($apiKey)) {
                    $request = $request->withToken($apiKey);
                }
                $response = $request->get($baseUrl.'/api/tags');
            } else {
                if (filled($apiKey)) {
                    $request = $request->withToken($apiKey);
                }
                $response = $request->get($baseUrl.'/models');
            }

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Nhà cung cấp trả về HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
                ];
            }

            $roleLabel = $role === 'review' ? ' cho đánh giá realtime' : ' cho kiểm tra từng URL';

            return ['success' => true, 'message' => 'Kết nối thành công đến '.$this->providerName($provider).$roleLabel.'.'];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => 'Không thể kết nối: '.mb_substr($error->getMessage(), 0, 500)];
        }
    }

    private function providerName(string $provider): string
    {
        return match ($provider) {
            'gemini' => 'Gemini',
            'anthropic' => 'Claude/Anthropic',
            'ollama' => 'Ollama',
            default => 'API tương thích OpenAI',
        };
    }
}
