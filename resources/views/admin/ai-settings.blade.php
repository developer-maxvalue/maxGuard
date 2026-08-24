@extends('layouts.app')

@section('title', 'Cài đặt AI')

@section('content')
    <div class="mg-breadcrumb"><a href="{{ route('admin.index') }}">Quản trị</a><i class="bi bi-chevron-right"></i><span>Cài đặt AI</span></div>

    <div class="mg-page-heading">
        <div>
            <h1>Cài đặt AI</h1>
            <p>Quản lý nhà cung cấp và thông tin kết nối AI dùng chung cho toàn bộ hệ thống.</p>
        </div>
        <span class="badge {{ $settings['source'] === 'database' ? 'badge-light-success' : 'badge-light-warning' }} fs-7">
            {{ $settings['source'] === 'database' ? 'Đang dùng cấu hình cơ sở dữ liệu' : 'Chưa lưu cấu hình cơ sở dữ liệu' }}
        </span>
    </div>

    <form method="POST" action="{{ route('admin.ai-settings.update') }}" class="row g-5" id="ai-settings-form">
        @csrf
        @method('PATCH')

        <div class="col-xl-8">
            <div class="card mg-card mb-5">
                <div class="card-header border-0 pt-2">
                    <div class="card-title d-block">
                        <h2 class="mg-card-title">Nhà cung cấp AI</h2>
                        <p class="mg-card-subtitle">Chọn một kết nối dùng cho phân tích chính sách và nội dung.</p>
                    </div>
                </div>
                <div class="card-body pt-1">
                    <label class="form-check form-switch form-check-custom form-check-solid mb-7">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled']))>
                        <span class="form-check-label fw-semibold">Bật phân tích AI cho hệ thống</span>
                    </label>

                    <div class="mb-6">
                        <label class="form-label fw-semibold">Nhà cung cấp</label>
                        <div class="mg-scan-options">
                            @foreach ([
                                ['gemini', 'Google Gemini', 'Kết nối trực tiếp Gemini API', 'bi-google'],
                                ['anthropic', 'Claude / Anthropic', 'Kết nối trực tiếp Claude API của Anthropic', 'bi-stars'],
                                ['ollama', 'Ollama', 'Mô hình chạy cục bộ qua Ollama', 'bi-pc-display'],
                                ['openai_compatible', 'Tương thích OpenAI', 'OpenAI, OpenRouter, LM Studio hoặc endpoint tương thích', 'bi-braces-asterisk'],
                            ] as $provider)
                                <label><input type="radio" name="provider" value="{{ $provider[0] }}" @checked(old('provider', $settings['provider']) === $provider[0])>
                                    <span><i class="bi {{ $provider[3] }}"></i><strong>{{ $provider[1] }}</strong><small>{{ $provider[2] }}</small></span>
                                </label>
                            @endforeach
                        </div>
                        @error('provider')<div class="text-danger fs-8 mt-2">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-5">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold" for="ai-base-url">URL máy chủ API</label>
                            <input id="ai-base-url" class="form-control form-control-solid @error('base_url') is-invalid @enderror" type="url" name="base_url" value="{{ old('base_url', $settings['base_url']) }}" required>
                            @error('base_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="ai-base-url-help"></div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold" for="ai-model">Tên mô hình</label>
                            <input id="ai-model" class="form-control form-control-solid @error('model') is-invalid @enderror" name="model" value="{{ old('model', $settings['model']) }}" required>
                            @error('model')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="form-label fw-semibold" for="ai-api-key">API key</label>
                        <input id="ai-api-key" class="form-control form-control-solid @error('api_key') is-invalid @enderror" type="password" name="api_key" autocomplete="new-password" placeholder="{{ $settings['has_api_key'] ? 'Đã lưu khóa mã hóa — để trống nếu không thay đổi' : 'Nhập API key nếu nhà cung cấp yêu cầu' }}">
                        @error('api_key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <span class="form-text" id="ai-key-help">Khóa được mã hóa bằng APP_KEY trước khi lưu vào cơ sở dữ liệu.</span>
                            @if ($settings['has_api_key'])
                                <label class="form-check form-check-sm"><input class="form-check-input" type="checkbox" name="clear_api_key" value="1"><span class="form-check-label text-danger">Xóa khóa đã lưu</span></label>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mg-card">
                <div class="card-header border-0 pt-2"><div class="card-title d-block"><h2 class="mg-card-title">Giới hạn và chất lượng</h2><p class="mg-card-subtitle">Kiểm soát chi phí, thời gian và độ nhạy của kết quả.</p></div></div>
                <div class="card-body pt-1">
                    <div class="row g-5">
                        <div class="col-md-6"><label class="form-label">Ngôn ngữ kết quả</label><input class="form-control" name="output_language" value="{{ old('output_language', $settings['output_language']) }}" required></div>
                        <div class="col-md-3"><label class="form-label">Trang tối đa / lượt quét</label><input class="form-control" type="number" name="max_pages_per_scan" min="0" max="100000" value="{{ old('max_pages_per_scan', $settings['max_pages_per_scan']) }}" required><div class="form-text">0 = không giới hạn</div></div>
                        <div class="col-md-3"><label class="form-label">Độ tin cậy tối thiểu</label><div class="input-group"><input class="form-control" type="number" name="min_confidence" min="0" max="100" value="{{ old('min_confidence', $settings['min_confidence']) }}" required><span class="input-group-text">%</span></div></div>
                        <div class="col-md-6"><label class="form-label">Ký tự đầu vào tối đa</label><input class="form-control" type="number" name="max_input_chars" min="1000" value="{{ old('max_input_chars', $settings['max_input_chars']) }}" required></div>
                        <div class="col-md-6"><label class="form-label">Token đầu ra tối đa</label><input class="form-control" type="number" name="max_output_tokens" min="100" value="{{ old('max_output_tokens', $settings['max_output_tokens']) }}" required></div>
                        <div class="col-md-6"><label class="form-label">Thời gian chờ kết nối</label><div class="input-group"><input class="form-control" type="number" name="connect_timeout_seconds" min="1" max="120" value="{{ old('connect_timeout_seconds', $settings['connect_timeout_seconds']) }}" required><span class="input-group-text">giây</span></div></div>
                        <div class="col-md-6"><label class="form-label">Thời gian chờ phản hồi</label><div class="input-group"><input class="form-control" type="number" name="timeout_seconds" min="5" max="3600" value="{{ old('timeout_seconds', $settings['timeout_seconds']) }}" required><span class="input-group-text">giây</span></div><div class="form-text">Claude Structured Outputs luôn dùng tối thiểu {{ number_format((int) config('maxguard.ai.anthropic_timeout_seconds', 300)) }} giây.</div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mg-card mb-5"><div class="card-body p-6">
                <h2 class="mg-card-title">Bảo mật</h2>
                <ul class="mg-check-list mt-5">
                    <li><i class="bi bi-check2"></i>API key được mã hóa trong cơ sở dữ liệu</li>
                    <li><i class="bi bi-check2"></i>Chỉ quản trị viên được thay đổi cấu hình</li>
                    <li><i class="bi bi-check2"></i>Khóa bí mật không được gửi lại trình duyệt</li>
                    <li><i class="bi bi-check2"></i>Cấu hình áp dụng cho cả queue worker</li>
                </ul>
            </div></div>

            @if ($setting)
                <div class="card mg-card mb-5"><div class="card-body p-6">
                    <div class="mg-eyebrow mb-3">Cập nhật gần nhất</div>
                    <strong class="d-block">{{ $setting->updatedBy?->name ?? 'Quản trị viên' }}</strong>
                    <span class="text-muted fs-8">{{ $setting->updated_at?->diffForHumans() }}</span>
                </div></div>
            @endif

            <div class="d-grid gap-3">
                <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-check2-circle me-2"></i>Lưu cài đặt</button>
                <button class="btn btn-light-primary" type="submit" name="test_connection" value="1"><i class="bi bi-plug me-2"></i>Lưu và kiểm tra kết nối</button>
            </div>
        </div>
    </form>
@endsection

@push('page-scripts')
<script>
(() => {
    const defaults = {
        gemini: {url: 'https://generativelanguage.googleapis.com/v1beta', model: 'gemini-2.5-flash', help: 'Gemini API v1beta; API key là bắt buộc.'},
        anthropic: {url: 'https://api.anthropic.com/v1', model: 'claude-sonnet-5', help: 'Claude Messages API; cần API key từ Anthropic Console, không dùng tài khoản đăng nhập claude.ai.'},
        ollama: {url: 'http://127.0.0.1:11434', model: 'qwen3:8b', help: 'URL gốc của Ollama; không cần thêm /api/chat.'},
        openai_compatible: {url: 'https://api.openai.com/v1', model: 'gpt-4.1-mini', help: 'URL gốc có endpoint /chat/completions và /models.'}
    };
    const url = document.getElementById('ai-base-url');
    const model = document.getElementById('ai-model');
    const help = document.getElementById('ai-base-url-help');
    const keyHelp = document.getElementById('ai-key-help');
    let previousProvider = document.querySelector('[name="provider"]:checked')?.value;
    const refresh = (replaceDefaults = false) => {
        const provider = document.querySelector('[name="provider"]:checked')?.value || 'gemini';
        const value = defaults[provider];
        if (replaceDefaults && previousProvider !== provider) {
            url.value = value.url;
            model.value = value.model;
        }
        help.textContent = value.help;
        keyHelp.textContent = provider === 'ollama'
            ? 'Ollama thường không cần API key. Nếu máy chủ có proxy xác thực, bạn vẫn có thể nhập khóa.'
            : 'Khóa được mã hóa bằng APP_KEY trước khi lưu vào cơ sở dữ liệu.';
        previousProvider = provider;
    };
    document.querySelectorAll('[name="provider"]').forEach(input => input.addEventListener('change', () => refresh(true)));
    refresh(false);
})();
</script>
@endpush
