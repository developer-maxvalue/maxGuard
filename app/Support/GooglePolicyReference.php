<?php

namespace App\Support;

final class GooglePolicyReference
{
    public static function title(string $category): string
    {
        return match ($category) {
            'Duplicate content', 'Copyright' => 'Nội dung sao chép và giá trị nội dung của nhà xuất bản',
            'Ad experience' => 'Chính sách về vị trí đặt quảng cáo AdSense',
            'Privacy & consent' => 'Nội dung bắt buộc trong chính sách quyền riêng tư',
            'Deceptive practices' => 'Chính sách về hành vi lừa đảo',
            'Technical trust' => 'Khắc phục sự cố trình thu thập dữ liệu AdSense',
            'Content quality' => 'Yêu cầu về chất lượng nội dung khi xét duyệt AdSense',
            default => 'Chính sách dành cho nhà xuất bản của Google',
        };
    }

    public static function url(string $category, ?string $reference = null): string
    {
        $reference = mb_strtolower((string) $reference);

        if ($category === 'Duplicate content' || str_contains($reference, 'replicated') || str_contains($reference, 'trùng lặp')) {
            return 'https://support.google.com/publisherpolicies/answer/11190248?hl=vi';
        }
        if ($category === 'Ad experience' || str_contains($reference, 'ad placement') || str_contains($reference, 'vị trí quảng cáo')) {
            return 'https://support.google.com/adsense/answer/1346295?hl=vi';
        }
        if ($category === 'Privacy & consent' || str_contains($reference, 'privacy') || str_contains($reference, 'quyền riêng tư')) {
            return 'https://support.google.com/adsense/answer/1348695?hl=vi';
        }
        if ($category === 'Deceptive practices' || str_contains($reference, 'deceptive') || str_contains($reference, 'lừa đảo')) {
            return 'https://support.google.com/publisherpolicies/answer/11185755?hl=vi';
        }
        if ($category === 'Technical trust') {
            return 'https://support.google.com/adsense/answer/2381908?hl=vi';
        }
        if ($category === 'Content quality') {
            return 'https://support.google.com/adsense/answer/81904?hl=vi';
        }

        return 'https://support.google.com/adsense/answer/10502938?hl=vi';
    }
}
