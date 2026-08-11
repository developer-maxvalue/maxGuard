<?php

namespace App\Services;

use App\Data\DetectorResult;
use App\Data\PageDocument;
use Symfony\Component\Process\Process;
use Throwable;

final class BrowserAdAuditor
{
    /** @var array<string, mixed> */
    private array $lastTrace = [];

    public function __construct(private SafeUrlValidator $validator) {}

    public function isConfigured(): bool
    {
        return (bool) config('maxguard.browser_audit.enabled')
            && is_file((string) config('maxguard.browser_audit.script'));
    }

    /** @return array<string, mixed> */
    public function lastTrace(): array
    {
        return $this->lastTrace;
    }

    /** @return list<DetectorResult> */
    public function analyze(PageDocument $page): array
    {
        $this->lastTrace = ['configured' => $this->isConfigured(), 'attempted' => false];
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            // Validate once in PHP as a first boundary. The browser helper
            // repeats validation for redirects and every requested resource.
            $this->validator->publicIps($page->url);
            $process = new Process([
                (string) config('maxguard.browser_audit.node_binary', 'node'),
                (string) config('maxguard.browser_audit.script'),
            ], base_path());
            $process->setTimeout(max(10, (int) config('maxguard.browser_audit.timeout_seconds', 45) + 10));
            $process->setInput(json_encode([
                'url' => $page->url,
                'timeoutMs' => max(5000, (int) config('maxguard.browser_audit.timeout_seconds', 45) * 1000),
                'settleMs' => max(250, (int) config('maxguard.browser_audit.settle_ms', 2500)),
                'proximityPx' => max(0, (int) config('maxguard.browser_audit.proximity_px', 24)),
                'userAgent' => (string) config('maxguard.crawler.user_agent'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $process->run();

            $this->lastTrace['attempted'] = true;
            if (! $process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Browser audit process failed.');
            }

            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || ($payload['ok'] ?? false) !== true) {
                throw new \RuntimeException((string) ($payload['error'] ?? 'Browser audit returned an invalid response.'));
            }

            $viewports = array_values(array_filter((array) ($payload['viewports'] ?? []), 'is_array'));
            $this->lastTrace += [
                'blocked_requests' => (int) ($payload['blockedRequests'] ?? 0),
                'viewports_checked' => count($viewports),
                'rendered_ads' => array_sum(array_map(fn (array $item): int => (int) ($item['adCount'] ?? 0), $viewports)),
            ];

            return $this->toFindings($viewports, (int) ($payload['blockedRequests'] ?? 0));
        } catch (Throwable $exception) {
            $this->lastTrace['error'] = mb_substr($exception->getMessage(), 0, 1000);

            return [];
        }
    }

    /** @param list<array<string, mixed>> $viewports @return list<DetectorResult> */
    private function toFindings(array $viewports, int $blockedRequests): array
    {
        $results = [];
        $sum = fn (string $key): int => array_sum(array_map(
            fn (array $item): int => (int) ($item[$key] ?? 0),
            $viewports,
        ));
        $max = fn (string $key): float => $viewports === [] ? 0.0 : max(array_map(
            fn (array $item): float => (float) ($item[$key] ?? 0),
            $viewports,
        ));
        $signals = ['viewports' => $viewports, 'blocked_private_requests' => $blockedRequests];

        $nearControls = $sum('nearInteractiveCount');
        if ($nearControls > 0) {
            $results[] = new DetectorResult(
                ruleKey: 'ads.browser-accidental-click-risk',
                category: 'Ad experience',
                severity: $nearControls >= 3 ? 'high' : 'review',
                confidence: 92,
                title: 'Quảng cáo hiển thị quá gần thành phần tương tác',
                summary: "Trình duyệt thật phát hiện {$nearControls} trường hợp quảng cáo chồng lấn hoặc nằm quá gần nút, liên kết hay trường nhập liệu; bố cục có thể tạo nhấp nhầm.",
                policyReference: 'Google AdSense — ad placement policies / avoiding accidental clicks',
                signals: $signals,
                remediation: ['Tăng khoảng cách giữa quảng cáo và nút điều hướng/tải xuống.', 'Kiểm tra lại cả desktop và mobile sau khi quảng cáo thực tế được tải.'],
            );
        }

        $overlays = $sum('intrusiveOverlayCount');
        if ($overlays > 0) {
            $results[] = new DetectorResult(
                ruleKey: 'ads.browser-intrusive-overlay',
                category: 'Ad experience',
                severity: 'high',
                confidence: 94,
                title: 'Quảng cáo dạng overlay có thể cản trở nội dung',
                summary: "Phát hiện {$overlays} quảng cáo hoặc vùng chứa quảng cáo cố định che một phần đáng kể màn hình.",
                policyReference: 'Google Publisher Policies — ads interfering with content',
                signals: $signals,
                remediation: ['Loại bỏ overlay che nội dung hoặc giảm kích thước vùng cố định.', 'Bảo đảm người dùng có thể đọc và điều hướng mà không phải tương tác với quảng cáo.'],
            );
        }

        $misleading = $sum('misleadingLabelCount');
        if ($misleading > 0) {
            $results[] = new DetectorResult(
                ruleKey: 'ads.browser-misleading-label',
                category: 'Ad experience',
                severity: 'high',
                confidence: 88,
                title: 'Nhãn hoặc ngữ cảnh gần quảng cáo có thể gây hiểu nhầm',
                summary: "Phát hiện {$misleading} quảng cáo nằm gần nhãn hoặc thành phần có thể khiến người dùng nhầm với điều hướng, tải xuống hay nội dung hữu ích.",
                policyReference: 'Google AdSense — placing ads under a misleading heading',
                signals: $signals,
                remediation: ['Chỉ dùng nhãn quảng cáo rõ ràng như “Quảng cáo” hoặc “Sponsored links”.', 'Tách quảng cáo khỏi menu, liên kết tải xuống và khuyến nghị nội dung.'],
            );
        }

        $coverage = $max('viewportCoverage');
        if ($coverage >= 0.30) {
            $results[] = new DetectorResult(
                ruleKey: 'ads.browser-viewport-dominance',
                category: 'Ad experience',
                severity: $coverage >= 0.50 ? 'high' : 'review',
                confidence: 86,
                title: 'Quảng cáo chiếm tỷ lệ lớn trong màn hình ban đầu',
                summary: 'Diện tích quảng cáo hiển thị chiếm tối đa '.round($coverage * 100).'% viewport trong lần kiểm tra.',
                policyReference: 'Google Publisher Policies — more ads or paid promotional material than publisher-content',
                signals: $signals,
                remediation: ['Giảm số lượng hoặc kích thước quảng cáo above-the-fold.', 'Đưa nội dung chính lên vị trí nổi bật hơn quảng cáo.'],
            );
        }

        $popups = $sum('popupAttempts');
        $refreshes = $sum('adRefreshCount');
        if ($popups > 0 || $refreshes > 0) {
            $results[] = new DetectorResult(
                ruleKey: 'ads.browser-popup-or-refresh',
                category: 'Ad experience',
                severity: $popups > 0 ? 'high' : 'review',
                confidence: 84,
                title: 'Phát hiện hành vi popup hoặc tự làm mới vùng quảng cáo',
                summary: "Quan sát được {$popups} lần thử mở cửa sổ mới và {$refreshes} lần vùng quảng cáo thay đổi nguồn sau khi tải.",
                policyReference: 'Google AdSense — pop-up, pop-under and auto-refresh placement policies',
                signals: $signals,
                remediation: ['Tắt popup/pop-under không do người dùng chủ động mở.', 'Kiểm tra cấu hình tự làm mới quảng cáo và chỉ sử dụng cơ chế được Google hỗ trợ.'],
            );
        }

        return $results;
    }
}
