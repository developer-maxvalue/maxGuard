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
            'provider' => $data['provider'],
            'base_url' => rtrim($data['base_url'], '/'),
            'model' => trim($data['model']),
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

        $setting->save();
        $configuration->apply();

        if ($request->boolean('test_connection')) {
            $result = $tester->test($setting->refresh());

            return back()->with($result['success'] ? 'status' : 'error', $result['message']);
        }

        return back()->with('status', 'Đã lưu cài đặt AI. Cấu hình cơ sở dữ liệu sẽ được dùng cho các lượt quét tiếp theo.');
    }
}
