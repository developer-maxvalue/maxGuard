<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;

final class AdExperienceDetector implements Detector
{
    public function key(): string
    {
        return 'ad-experience';
    }

    public function detect(PageDocument $page): array
    {
        if ($page->adCount === 0) {
            return [];
        }

        $maxAds = (int) config('maxguard.thresholds.max_ads_per_page', 6);
        $minWordsPerAd = (int) config('maxguard.thresholds.min_words_per_ad', 220);
        $emptyContentWords = (int) config('maxguard.thresholds.ad_page_empty_content_words', 80);
        $thinContentWords = (int) config('maxguard.thresholds.ad_page_thin_content_words', 300);
        $ratio = (int) floor($page->wordCount / max(1, $page->adCount));

        if ($page->wordCount < $emptyContentWords) {
            return [new DetectorResult(
                ruleKey: 'ads.on-page-without-content',
                category: 'Ad experience',
                severity: 'high',
                confidence: 96,
                title: 'Trang gần như không có nội dung nhưng vẫn chứa quảng cáo',
                summary: "Trang chỉ có {$page->wordCount} từ có thể đọc nhưng phát hiện {$page->adCount} tín hiệu/vị trí quảng cáo. Đây là trường hợp cần xử lý sớm vì quảng cáo đang xuất hiện trên trang không có đủ nội dung của nhà xuất bản.",
                policyReference: 'Chính sách dành cho nhà xuất bản của Google — quảng cáo trên màn hình không có nội dung của nhà xuất bản hoặc có nội dung giá trị thấp',
                signals: [
                    'ad_count' => $page->adCount,
                    'word_count' => $page->wordCount,
                    'words_per_ad' => $ratio,
                    'content_state' => 'empty_or_nearly_empty',
                ],
                remediation: [
                    'Tắt hoặc loại bỏ mã quảng cáo khỏi trang cho đến khi có đủ nội dung chính có giá trị.',
                    'Kiểm tra template, trang lỗi, trang tag/search, trang tải dở và các URL được tạo tự động.',
                    'Bổ sung nội dung nguyên bản hoặc đặt noindex và không phân phối quảng cáo trên URL này.',
                ],
            )];
        }

        if ($page->wordCount < $thinContentWords) {
            return [new DetectorResult(
                ruleKey: 'ads.on-thin-content-page',
                category: 'Ad experience',
                severity: $page->wordCount < 150 || $ratio < 100 ? 'high' : 'review',
                confidence: 93,
                title: 'Trang có quảng cáo nhưng nội dung quá ít',
                summary: "Trang có {$page->wordCount} từ và {$page->adCount} tín hiệu/vị trí quảng cáo, tương đương khoảng {$ratio} từ cho mỗi quảng cáo.",
                policyReference: 'Chính sách dành cho nhà xuất bản của Google — quảng cáo trên màn hình có nội dung giá trị thấp hoặc nội dung của nhà xuất bản không đầy đủ',
                signals: [
                    'ad_count' => $page->adCount,
                    'word_count' => $page->wordCount,
                    'words_per_ad' => $ratio,
                    'content_state' => 'thin',
                ],
                remediation: [
                    'Tạm dừng quảng cáo trên URL này hoặc bổ sung nội dung nguyên bản, hữu ích và đầy đủ.',
                    'Giảm số vị trí quảng cáo để nội dung chính chiếm ưu thế rõ ràng.',
                    'Quét lại URL sau khi cập nhật nội dung và bố cục quảng cáo.',
                ],
            )];
        }

        if ($page->adCount <= $maxAds && $ratio >= $minWordsPerAd) {
            return [];
        }

        $severe = $page->adCount >= $maxAds + 3 || $ratio < 100;

        return [new DetectorResult(
            ruleKey: 'ads.density-review',
            category: 'Ad experience',
            severity: $severe ? 'high' : 'review',
            confidence: 88,
            title: 'Mật độ quảng cáo có thể lấn át nội dung trang',
            summary: "Phát hiện {$page->adCount} vị trí quảng cáo, trung bình khoảng một quảng cáo trên {$ratio} từ có thể đọc.",
            policyReference: 'Chính sách dành cho nhà xuất bản của Google — số lượng quảng cáo hoặc nội dung quảng bá trả phí nhiều hơn nội dung của nhà xuất bản',
            signals: ['ad_count' => $page->adCount, 'word_count' => $page->wordCount, 'words_per_ad' => $ratio],
            remediation: [
                'Giảm số đơn vị quảng cáo và duy trì trải nghiệm đọc ưu tiên nội dung.',
                'Kiểm tra thủ công vị trí trên màn hình di động và nguy cơ nhấp nhầm.',
                'Tách biệt trực quan điều hướng và các nút tương tác khỏi quảng cáo.',
            ],
        )];
    }
}
