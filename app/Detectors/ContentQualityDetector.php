<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class ContentQualityDetector implements Detector
{
    public function key(): string
    {
        return 'content-quality';
    }

    public function detect(PageDocument $page): array
    {
        $results = [];
        $thin = (int) config('maxguard.thresholds.thin_content_words', 300);
        $lowValue = (int) config('maxguard.thresholds.low_value_words', 600);

        if ($page->wordCount < $thin) {
            $results[] = new DetectorResult(
                ruleKey: 'content.thin-page',
                category: 'Content quality',
                severity: $page->wordCount < 150 ? 'high' : 'review',
                confidence: 92,
                title: 'Nội dung trang mỏng hoặc không đầy đủ',
                summary: "Chỉ tìm thấy {$page->wordCount} từ có thể đọc. Trang có ít giá trị biên tập nguyên bản có thể không phù hợp để kiếm tiền.",
                policyReference: 'Chính sách dành cho nhà xuất bản của Google — xem xét nội dung giá trị thấp hoặc không đầy đủ',
                signals: ['word_count' => $page->wordCount, 'paragraph_count' => $page->meta['paragraph_count'] ?? 0],
                remediation: [
                    'Bổ sung nội dung tường thuật, phân tích hoặc kiến thức chuyên môn trực tiếp có giá trị và nguyên bản.',
                    'Loại trang khỏi chương trình kiếm tiền nếu trang chỉ tồn tại để chứa quảng cáo hoặc nội dung nhúng.',
                    'Kiểm tra điều hướng và bảo đảm trang có mục đích rõ ràng cho người dùng.',
                ],
            );
        } elseif ($page->wordCount < $lowValue && ($page->meta['paragraph_count'] ?? 0) < 3) {
            $results[] = new DetectorResult(
                ruleKey: 'content.low-editorial-structure',
                category: 'Content quality',
                severity: 'review',
                confidence: 76,
                title: 'Cấu trúc biên tập còn hạn chế',
                summary: 'Trang có đủ văn bản thô nhưng rất ít nội dung biên tập có cấu trúc.',
                policyReference: 'Chính sách dành cho nhà xuất bản của Google — xem xét khoảng không quảng cáo có giá trị',
                signals: ['word_count' => $page->wordCount, 'paragraph_count' => $page->meta['paragraph_count'] ?? 0],
                remediation: ['Cải thiện cấu trúc bài viết, nguồn tham khảo, thông tin tác giả và phần giải thích hướng đến người dùng.'],
            );
        }

        if ($page->title === '' || $page->h1Count !== 1) {
            $results[] = new DetectorResult(
                ruleKey: 'content.document-structure',
                category: 'Content quality',
                severity: 'info',
                confidence: 98,
                title: 'Tiêu đề trang hoặc cấu trúc đề mục cần được xem xét',
                summary: 'Tiêu đề rõ ràng và một đề mục chính giúp người dùng cùng người kiểm duyệt hiểu mục đích của trang.',
                signals: ['title_present' => $page->title !== '', 'h1_count' => $page->h1Count],
                remediation: ['Thêm tiêu đề mô tả cho tài liệu và một đề mục H1 chính.'],
            );
        }

        return $results;
    }
}
