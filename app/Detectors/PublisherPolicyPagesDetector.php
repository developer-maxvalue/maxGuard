<?php

namespace App\Detectors;

use App\Contracts\Detector;
use App\Data\DetectorResult;
use App\Data\PageDocument;
use App\Support\EssentialPublisherPages;

final class PublisherPolicyPagesDetector implements Detector
{
    public function key(): string
    {
        return 'publisher-policy-pages';
    }

    public function detect(PageDocument $page): array
    {
        $type = (string) ($page->meta['essential_page_type'] ?? EssentialPublisherPages::classify($page->url) ?? '');

        if ($type === 'home') {
            return $this->inspectHomePageLinks($page);
        }

        if (! in_array($type, EssentialPublisherPages::linkedTypes(), true)) {
            return [];
        }

        return $this->inspectRequiredPage($page, $type);
    }

    /** @return list<DetectorResult> */
    private function inspectHomePageLinks(PageDocument $page): array
    {
        $present = [];
        foreach ((array) ($page->meta['links_with_text'] ?? []) as $link) {
            if (! is_array($link)) {
                continue;
            }
            $type = EssentialPublisherPages::classify((string) ($link['href'] ?? ''), (string) ($link['text'] ?? ''));
            if ($type !== null && $type !== 'home') {
                $present[$type] = true;
            }
        }

        $missing = array_values(array_diff(EssentialPublisherPages::linkedTypes(), array_keys($present)));
        if ($missing === []) {
            return [];
        }

        $labels = array_map(EssentialPublisherPages::label(...), $missing);

        return [new DetectorResult(
            ruleKey: 'publisher.required-pages-missing',
            category: 'Publisher requirements',
            severity: 'high',
            confidence: 96,
            title: 'Thiếu liên kết đến các trang thông tin và minh bạch của nhà xuất bản',
            summary: 'Không tìm thấy từ trang chủ: '.implode(', ', $labels).'. Chính sách quyền riêng tư và disclosure liên quan là yêu cầu trực tiếp khi dùng sản phẩm Google; các trang còn lại là tín hiệu giúp thể hiện danh tính, tính minh bạch và trách nhiệm của nhà xuất bản.',
            policyReference: 'Google Publisher Policies — privacy disclosures, publisher transparency and trustworthy inventory',
            signals: ['required_types' => EssentialPublisherPages::linkedTypes(), 'present_types' => array_keys($present), 'missing_types' => $missing],
            remediation: ['Tạo các trang còn thiếu với nội dung chính xác.', 'Đặt liên kết rõ ràng trong menu hoặc chân trang trên toàn website.', 'Chạy lại lượt quét toàn diện sau khi xuất bản.'],
        )];
    }

    /** @return list<DetectorResult> */
    private function inspectRequiredPage(PageDocument $page, string $type): array
    {
        $text = EssentialPublisherPages::normalize($page->text);
        $checks = $this->checks($page, $type, $text);
        $failed = array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed));
        if ($failed === []) {
            return [];
        }

        $privacy = $type === 'privacy';

        return [new DetectorResult(
            ruleKey: 'publisher.required-page-incomplete.'.$type,
            category: $privacy ? 'Privacy & consent' : 'Publisher requirements',
            severity: $privacy ? 'high' : 'review',
            confidence: 90,
            title: 'Trang '.EssentialPublisherPages::label($type).' chưa có nội dung đầy đủ',
            summary: 'Trang đã được tìm thấy và quét nhưng còn thiếu các tín hiệu bắt buộc: '.implode(', ', $failed).'.',
            policyReference: $privacy
                ? 'Google Publisher Policies — privacy-related policies and required disclosures'
                : 'Google Publisher Policies — publisher transparency and trustworthy inventory review',
            signals: ['essential_page_type' => $type, 'checks' => $checks, 'failed_checks' => $failed, 'word_count' => $page->wordCount],
            remediation: $this->remediation($type),
        )];
    }

    /** @return array<string, bool> */
    private function checks(PageDocument $page, string $type, string $text): array
    {
        return match ($type) {
            'about' => [
                'nội dung mô tả đủ chi tiết' => $page->wordCount >= 80,
                'danh tính hoặc đơn vị xuất bản' => $this->contains($text, ['publisher', 'company', 'organization', 'owner', 'editorial team', 'nha xuat ban', 'cong ty', 'to chuc', 'chu so huu', 'doi ngu bien tap', 'chung toi']),
            ],
            'contact' => [
                'phương thức liên hệ trực tiếp' => preg_match('/(?:mailto:|tel:|<form\b)/i', $page->html) === 1
                    || preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $page->text) === 1,
                'mô tả mục đích liên hệ' => $page->wordCount >= 20,
            ],
            'privacy' => [
                'mô tả dữ liệu được thu thập' => $this->contains($text, ['collect', 'personal data', 'personal information', 'thu thap', 'du lieu ca nhan', 'thong tin ca nhan']),
                'cookie, địa chỉ IP hoặc mã nhận dạng' => $this->contains($text, ['cookie', 'web beacon', 'ip address', 'identifier', 'dia chi ip', 'ma nhan dang']),
                'Google, quảng cáo hoặc bên thứ ba' => $this->contains($text, ['google', 'adsense', 'advertising', 'third party', 'quang cao', 'ben thu ba']),
                'mục đích sử dụng hoặc chia sẻ dữ liệu' => $this->contains($text, ['use', 'share', 'process', 'consent', 'su dung', 'chia se', 'xu ly', 'dong y']),
                'nội dung chính sách đủ chi tiết' => $page->wordCount >= 150,
            ],
            'terms' => [
                'điều kiện sử dụng' => $this->contains($text, ['terms', 'conditions', 'acceptable use', 'dieu khoan', 'dieu kien', 'su dung website']),
                'nội dung điều khoản đủ chi tiết' => $page->wordCount >= 100,
            ],
            'copyright' => [
                'quyền sở hữu hoặc giấy phép nội dung' => $this->contains($text, ['copyright', 'dmca', 'license', 'intellectual property', 'ban quyen', 'giay phep', 'so huu tri tue']),
                'quy trình báo cáo vi phạm' => $this->contains($text, ['report', 'notice', 'infringement', 'contact', 'bao cao', 'thong bao', 'vi pham', 'lien he']),
                'nội dung chính sách đủ chi tiết' => $page->wordCount >= 80,
            ],
            'editorial' => [
                'tiêu chuẩn biên tập và nguồn tin' => $this->contains($text, ['editorial', 'source', 'author', 'fact check', 'bien tap', 'nguon tin', 'tac gia', 'kiem chung']),
                'cơ chế sửa lỗi hoặc cập nhật' => $this->contains($text, ['correction', 'update', 'error', 'sua loi', 'cap nhat', 'dinh chinh']),
                'nội dung chính sách đủ chi tiết' => $page->wordCount >= 100,
            ],
            'disclaimer' => [
                'phạm vi và giới hạn trách nhiệm' => $this->contains($text, ['disclaimer', 'liability', 'professional advice', 'no warranty', 'mien tru', 'trach nhiem', 'tu van chuyen mon', 'khong bao dam']),
                'nội dung tuyên bố đủ chi tiết' => $page->wordCount >= 80,
            ],
            default => [],
        };
    }

    /** @param list<string> $needles */
    private function contains(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, EssentialPublisherPages::normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function remediation(string $type): array
    {
        if ($type === 'privacy') {
            return [
                'Công bố rõ việc thu thập, chia sẻ và sử dụng dữ liệu phát sinh từ các sản phẩm Google.',
                'Nêu rõ cookie, web beacon, địa chỉ IP hoặc mã nhận dạng mà Google và bên thứ ba có thể sử dụng.',
                'Liên kết chính sách này rõ ràng từ mọi trang và triển khai CMP phù hợp tại các khu vực bắt buộc.',
            ];
        }

        return [
            'Bổ sung đầy đủ các mục còn thiếu bằng thông tin thật của nhà xuất bản.',
            'Cung cấp phương thức liên hệ và quy trình chịu trách nhiệm rõ ràng khi phù hợp.',
            'Đặt liên kết đến trang trong menu hoặc chân trang trên toàn website.',
        ];
    }
}
