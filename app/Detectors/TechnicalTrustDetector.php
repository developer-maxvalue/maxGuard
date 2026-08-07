<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class TechnicalTrustDetector implements Detector
{
    public function key(): string
    {
        return 'technical-trust';
    }

    public function detect(PageDocument $page): array
    {
        $results = [];

        if (parse_url($page->url, PHP_URL_SCHEME) !== 'https') {
            $results[] = new DetectorResult(
                ruleKey: 'technical.no-https',
                category: 'Technical trust',
                severity: 'high',
                confidence: 100,
                title: 'Trang không được cung cấp qua HTTPS',
                summary: 'Trang được quét đang dùng kết nối HTTP không mã hóa.',
                signals: ['scheme' => parse_url($page->url, PHP_URL_SCHEME)],
                remediation: ['Bật HTTPS trên toàn website và chuyển hướng URL HTTP sang URL HTTPS tương ứng.'],
            );
        }

        if ($page->isHomePage() && (! ($page->meta['has_about_link'] ?? false) || ! ($page->meta['has_contact_link'] ?? false))) {
            $results[] = new DetectorResult(
                ruleKey: 'trust.publisher-identity-links',
                category: 'Content quality',
                severity: 'review',
                confidence: 84,
                title: 'Liên kết nhận diện nhà xuất bản chưa đầy đủ',
                summary: 'Không thể dễ dàng tìm thấy liên kết Giới thiệu hoặc Liên hệ từ trang chủ.',
                signals: ['about_link' => $page->meta['has_about_link'] ?? false, 'contact_link' => $page->meta['has_contact_link'] ?? false],
                remediation: ['Bổ sung thông tin rõ ràng về Giới thiệu, Liên hệ, quyền sở hữu và trách nhiệm biên tập.'],
            );
        }

        return $results;
    }
}
