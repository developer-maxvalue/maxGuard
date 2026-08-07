<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class CopyrightSignalsDetector implements Detector
{
    public function key(): string
    {
        return 'copyright-signals';
    }

    public function detect(PageDocument $page): array
    {
        $external = (int) ($page->meta['external_images'] ?? 0);
        if ($external === 0) {
            return [];
        }

        $hasAttribution = preg_match('/\b(source|credit|courtesy|licensed?|photo by|©)\b/iu', $page->text) === 1;
        if ($hasAttribution) {
            return [];
        }

        return [new DetectorResult(
            ruleKey: 'copyright.media-provenance-unverified',
            category: 'Copyright',
            severity: $external >= 3 ? 'high' : 'review',
            confidence: $external >= 3 ? 82 : 68,
            title: 'Nguồn gốc nội dung đa phương tiện bên ngoài chưa được xác minh',
            summary: "Tìm thấy {$external} hình ảnh lưu trữ bên ngoài nhưng không có dấu hiệu cấp phép hoặc ghi nguồn rõ ràng. Đây là tín hiệu cần xem xét, không phải kết luận pháp lý.",
            policyReference: 'Chính sách dành cho nhà xuất bản của Google — lạm dụng quyền sở hữu trí tuệ',
            signals: [
                'external_images' => $external,
                'external_image_urls' => array_slice((array) ($page->meta['external_image_urls'] ?? []), 0, 20),
                'visible_attribution' => false,
                'source_status' => 'unverified_external_media',
            ],
            remediation: [
                'Xác minh quyền sở hữu, giấy phép hoặc văn bản cho phép đối với mọi nội dung đa phương tiện.',
                'Lưu hợp đồng, hóa đơn hoặc bằng chứng cho phép trong hồ sơ khắc phục.',
                'Thay nội dung chưa xác minh bằng nội dung thuộc sở hữu hoặc được cấp phép hợp lệ.',
            ],
        )];
    }
}
