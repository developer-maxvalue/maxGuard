<?php

namespace App\Services;

use App\Models\AiSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AiConnectionTester
{
    /** @return array{success: bool, message: string} */
    public function test(AiSetting $setting): array
    {
        try {
            $baseUrl = rtrim($setting->base_url, '/');
            $request = Http::acceptJson()
                ->connectTimeout(min(20, $setting->connect_timeout_seconds))
                ->timeout(min(30, $setting->timeout_seconds))
                ->retry(1, 300, fn (Throwable $error): bool => $error instanceof ConnectionException, false);

            if ($setting->provider === 'gemini') {
                if (blank($setting->api_key)) {
                    return ['success' => false, 'message' => 'Gemini yêu cầu API key.'];
                }
                $response = $request->get($baseUrl.'/models', ['key' => $setting->api_key]);
            } elseif ($setting->provider === 'ollama') {
                if (filled($setting->api_key)) {
                    $request = $request->withToken($setting->api_key);
                }
                $response = $request->get($baseUrl.'/api/tags');
            } else {
                if (filled($setting->api_key)) {
                    $request = $request->withToken($setting->api_key);
                }
                $response = $request->get($baseUrl.'/models');
            }

            if (! $response->successful()) {
                return [
                    'success' => false,
                    'message' => 'Nhà cung cấp trả về HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
                ];
            }

            return ['success' => true, 'message' => 'Kết nối thành công đến '.$this->providerName($setting->provider).'.'];
        } catch (Throwable $error) {
            return ['success' => false, 'message' => 'Không thể kết nối: '.mb_substr($error->getMessage(), 0, 500)];
        }
    }

    private function providerName(string $provider): string
    {
        return match ($provider) {
            'gemini' => 'Gemini',
            'ollama' => 'Ollama',
            default => 'API tương thích OpenAI',
        };
    }
}
