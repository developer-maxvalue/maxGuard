<?php

namespace App\Support;

final class UiText
{
    private const LABELS = [
        'Copyright' => 'Bản quyền',
        'Duplicate content' => 'Nội dung trùng lặp',
        'Ad experience' => 'Trải nghiệm quảng cáo',
        'Content quality' => 'Chất lượng nội dung',
        'Privacy & consent' => 'Quyền riêng tư và đồng ý',
        'Prohibited content' => 'Nội dung bị cấm',
        'Deceptive practices' => 'Hành vi lừa đảo',
        'Technical trust' => 'Độ tin cậy kỹ thuật',
        'Third-party moderation' => 'Kiểm duyệt bên thứ ba',
        'demo_evidence' => 'Bằng chứng minh họa',
        'manual_review_required' => 'Cần xem xét thủ công',
        'ad_count' => 'Số quảng cáo',
        'word_count' => 'Số từ',
        'words_per_ad' => 'Số từ mỗi quảng cáo',
        'external_images' => 'Hình ảnh bên ngoài',
        'visible_attribution' => 'Ghi nguồn hiển thị',
        'similarity' => 'Độ tương đồng',
        'matched_url' => 'URL trùng khớp',
        'privacy_link_found' => 'Đã tìm thấy liên kết quyền riêng tư',
        'cmp_signal_found' => 'Đã tìm thấy tín hiệu CMP',
        'title_present' => 'Có tiêu đề',
        'h1_count' => 'Số đề mục H1',
        'crawl' => 'Thu thập dữ liệu',
        'reuse' => 'Tái sử dụng kết quả',
        'local_rules' => 'Quy tắc cục bộ',
        'browser_audit' => 'Kiểm tra quảng cáo bằng trình duyệt',
        'external_copy' => 'Đối chiếu nội dung ngoài website',
        'sightengine' => 'Sightengine',
        'gemini' => 'Gemini',
        'pipeline' => 'Quy trình xử lý',
        'waiting' => 'Đang chờ',
        'critical' => 'Nghiêm trọng',
        'high' => 'Cao',
        'review' => 'Cần xem xét',
        'healthy' => 'Tốt',
        'pending' => 'Đang chờ',
        'scanning' => 'Đang quét',
        'disabled' => 'Đã tắt',
        'open' => 'Đang mở',
        'investigating' => 'Đang điều tra',
        'remediating' => 'Đang khắc phục',
        'resolved' => 'Đã xử lý',
        'queued' => 'Đang chờ',
        'running' => 'Đang chạy',
        'completed' => 'Hoàn tất',
        'partial' => 'Một phần',
        'reused' => 'Đã tái sử dụng',
        'failed' => 'Thất bại',
        'cancelled' => 'Đã hủy',
        'info' => 'Thông tin',
    ];

    private const TEXTS = [
        'Copied article and unlicensed media' => 'Bài viết sao chép và nội dung đa phương tiện chưa được cấp phép',
        'Substantial content similarity' => 'Nội dung có độ tương đồng đáng kể',
        'Substantial internal content similarity' => 'Nội dung nội bộ có độ tương đồng đáng kể',
        'Mobile ad density exceeds threshold' => 'Mật độ quảng cáo trên thiết bị di động vượt ngưỡng',
        'Ad density may overwhelm page content' => 'Mật độ quảng cáo có thể lấn át nội dung trang',
        'External media provenance is unverified' => 'Nguồn gốc nội dung đa phương tiện bên ngoài chưa được xác minh',
        'Thin or insufficient page content' => 'Nội dung trang mỏng hoặc không đầy đủ',
        'Limited editorial structure' => 'Cấu trúc biên tập còn hạn chế',
        'Page title or heading structure needs review' => 'Tiêu đề trang hoặc cấu trúc đề mục cần được xem xét',
        'Privacy or cookie disclosure link not found' => 'Không tìm thấy liên kết thông báo quyền riêng tư hoặc cookie',
        'Consent management signal was not detected' => 'Không phát hiện tín hiệu quản lý sự đồng ý',
        'Page is not served over HTTPS' => 'Trang không được cung cấp qua HTTPS',
        'Publisher identity links are incomplete' => 'Liên kết nhận diện nhà xuất bản chưa đầy đủ',
        'ads.txt is missing or empty' => 'Thiếu tệp ads.txt hoặc tệp đang trống',
        'Google seller entry not detected in ads.txt' => 'Không phát hiện mục người bán Google trong ads.txt',
        'The page requires manual verification of text and media ownership.' => 'Trang cần được xác minh thủ công về quyền sở hữu văn bản và nội dung đa phương tiện.',
        'The article substantially overlaps another indexed page.' => 'Bài viết trùng lặp đáng kể với một trang khác đã được lập chỉ mục.',
        'The content-to-ad ratio requires a manual mobile review.' => 'Tỷ lệ nội dung trên quảng cáo cần được kiểm tra thủ công trên thiết bị di động.',
        'Review the evidence.' => 'Xem xét bằng chứng.',
        'Verify rights or remove the affected material.' => 'Xác minh quyền sử dụng hoặc xóa nội dung bị ảnh hưởng.',
        'Run a follow-up scan.' => 'Chạy lượt quét theo dõi.',
        'Review the evidence and document the remediation decision.' => 'Xem xét bằng chứng và ghi lại quyết định khắc phục.',
        'Google Publisher Policies — intellectual property abuse' => 'Chính sách dành cho nhà xuất bản của Google — lạm dụng quyền sở hữu trí tuệ',
        'Google Publisher Policies — low-value/reused inventory review' => 'Chính sách dành cho nhà xuất bản của Google — xem xét nội dung giá trị thấp hoặc tái sử dụng',
        'Google Publisher Policies — ad placement review' => 'Chính sách dành cho nhà xuất bản của Google — xem xét vị trí quảng cáo',
    ];

    public static function label(?string $value): string
    {
        return self::LABELS[$value ?? ''] ?? (string) $value;
    }

    public static function text(?string $value): string
    {
        return self::TEXTS[$value ?? ''] ?? (string) $value;
    }

    /** @param array<int, string>|null $values */
    public static function texts(?array $values): array
    {
        return array_map(self::text(...), $values ?? []);
    }
}
