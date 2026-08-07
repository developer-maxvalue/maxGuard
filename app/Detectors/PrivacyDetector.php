<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class PrivacyDetector implements Detector
{
    public function key(): string
    {
        return 'privacy';
    }

    public function detect(PageDocument $page): array
    {
        if (! $page->isHomePage()) {
            return [];
        }

        $results = [];
        if (! ($page->meta['has_privacy_link'] ?? false)) {
            $results[] = new DetectorResult(
                ruleKey: 'privacy.missing-disclosure-link',
                category: 'Privacy & consent',
                severity: 'high',
                confidence: 94,
                title: 'Không tìm thấy liên kết thông báo quyền riêng tư hoặc cookie',
                summary: 'Trang chủ không hiển thị liên kết dễ tìm đến thông báo về quyền riêng tư hoặc cookie.',
                policyReference: 'Chính sách dành cho nhà xuất bản của Google — thông báo liên quan đến quyền riêng tư',
                signals: ['privacy_link_found' => false],
                remediation: ['Đăng chính sách quyền riêng tư chính xác và liên kết từ điều hướng cố định hoặc chân trang.'],
            );
        }

        if ($page->adCount > 0 && ! ($page->meta['has_consent_signal'] ?? false)) {
            $results[] = new DetectorResult(
                ruleKey: 'privacy.consent-signal-missing',
                category: 'Privacy & consent',
                severity: 'review',
                confidence: 72,
                title: 'Không phát hiện tín hiệu quản lý sự đồng ý',
                summary: 'Đã phát hiện quảng cáo nhưng HTML ban đầu không có các dấu hiệu CMP hoặc đồng ý cookie phổ biến.',
                policyReference: 'Yêu cầu về sự đồng ý của Google — xem xét cách triển khai',
                signals: ['ad_count' => $page->adCount, 'cmp_signal_found' => false],
                remediation: ['Xác minh hoạt động CMP trên trình duyệt thực theo từng khu vực; chỉ HTML máy chủ không thể chứng minh việc tuân thủ sự đồng ý.'],
            );
        }

        return $results;
    }
}
