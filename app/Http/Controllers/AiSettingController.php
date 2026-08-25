<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAiSettingRequest;
use App\Models\AiSetting;
use App\Services\AiConfiguration;
use App\Services\AiConnectionTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class AiSettingController extends Controller
{
    public function index(AiConfiguration $configuration): View
    {
        $setting = AiSetting::query()->latest('id')->first();

        return view('admin.ai-settings', [
            'settings' => $configuration->formValues(),
            'setting' => $setting,
        ]);
    }

    public function update(
        UpdateAiSettingRequest $request,
        AiConfiguration $configuration,
        AiConnectionTester $tester,
    ): RedirectResponse {
        $data = $request->validated();
        $setting = AiSetting::query()->latest('id')->first() ?? new AiSetting;

        $setting->fill([
            'enabled' => $request->boolean('enabled'),
            'review_enabled' => $request->boolean('review_enabled'),
            'provider' => $data['provider'],
            'review_provider' => $data['review_provider'],
            'base_url' => rtrim($data['base_url'], '/'),
            'review_base_url' => rtrim($data['review_base_url'], '/'),
            'model' => trim($data['model']),
            'review_model' => trim($data['review_model']),
            'output_language' => trim($data['output_language']),
            'max_pages_per_scan' => $data['max_pages_per_scan'],
            'min_confidence' => $data['min_confidence'],
            'max_input_chars' => $data['max_input_chars'],
            'max_output_tokens' => $data['max_output_tokens'],
            'connect_timeout_seconds' => $data['connect_timeout_seconds'],
            'timeout_seconds' => $data['timeout_seconds'],
            'updated_by' => $request->user()->id,
        ]);

        if ($request->boolean('clear_api_key')) {
            $setting->api_key = null;
        } elseif (filled($data['api_key'] ?? null)) {
            $setting->api_key = trim($data['api_key']);
        }
        if ($request->boolean('clear_review_api_key')) {
            $setting->review_api_key = null;
        } elseif (filled($data['review_api_key'] ?? null)) {
            $setting->review_api_key = trim($data['review_api_key']);
        }

        $setting->save();
        $configuration->apply();

        if (filled($data['test_connection'] ?? null)) {
            $result = $tester->test($setting->refresh(), $data['test_connection']);

            return back()->with($result['success'] ? 'status' : 'error', $result['message']);
        }

        return back()->with('status', 'Đã lưu cài đặt AI. Cấu hình cơ sở dữ liệu sẽ được dùng cho các lượt quét tiếp theo.');
    }
}
