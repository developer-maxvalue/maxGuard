<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class AdsTxtDetector implements Detector
{
    public function key(): string
    {
        return 'ads-txt';
    }

    public function detect(PageDocument $page): array
    {
        if (! $page->isHomePage()) {
            return [];
        }

        if (! ($page->meta['ads_txt_present'] ?? false)) {
            return [new DetectorResult(
                ruleKey: 'ads-txt.missing-or-empty',
                category: 'Ad experience',
                severity: 'high',
                confidence: 98,
                title: 'Thiếu tệp ads.txt hoặc tệp đang trống',
                summary: 'Trình thu thập không tìm thấy tệp ads.txt hợp lệ tại thư mục gốc của website.',
                policyReference: 'IAB ads.txt / xem xét ủy quyền khoảng không quảng cáo Google AdSense',
                signals: ['http_status' => $page->meta['ads_txt_status'] ?? null, 'authorized_lines' => 0],
                remediation: ['Đăng tệp ads.txt hợp lệ tại tên miền gốc và xác minh các giá trị mã nhà xuất bản.'],
            )];
        }

        if (! ($page->meta['ads_txt_has_google'] ?? false)) {
            return [new DetectorResult(
                ruleKey: 'ads-txt.google-entry-review',
                category: 'Ad experience',
                severity: 'review',
                confidence: 86,
                title: 'Không phát hiện mục người bán Google trong ads.txt',
                summary: 'Tệp ads.txt tồn tại nhưng không tìm thấy dòng nào bắt đầu bằng google.com.',
                policyReference: 'Xem xét ủy quyền ads.txt của Google AdSense',
                signals: ['authorized_lines' => $page->meta['ads_txt_lines'] ?? 0, 'google_entry' => false],
                remediation: ['Đối chiếu ads.txt với khai báo nhà xuất bản chính xác hiển thị trong tài khoản AdSense.'],
            )];
        }

        return [];
    }
}
