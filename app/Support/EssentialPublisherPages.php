<?php

namespace App\Support;

use Illuminate\Support\Str;

final class EssentialPublisherPages
{
    /** @var array<string, string> */
    public const LABELS = [
        'home' => 'Trang chủ',
        'about' => 'Giới thiệu',
        'contact' => 'Liên hệ',
        'privacy' => 'Quyền riêng tư/Cookie',
        'terms' => 'Điều khoản sử dụng',
        'copyright' => 'Bản quyền/DMCA',
        'editorial' => 'Chính sách biên tập',
        'disclaimer' => 'Tuyên bố miễn trừ',
    ];

    /** @var array<string, list<string>> */
    private const SLUGS = [
        'privacy' => ['privacy', 'privacy-policy', 'cookie', 'cookie-policy', 'cookies', 'chinh-sach-bao-mat', 'bao-mat-thong-tin'],
        'copyright' => ['copyright', 'dmca', 'ban-quyen', 'so-huu-tri-tue', 'intellectual-property'],
        'editorial' => ['editorial', 'editorial-policy', 'editorial-guidelines', 'chinh-sach-bien-tap', 'quy-trinh-bien-tap'],
        'disclaimer' => ['disclaimer', 'mien-tru-trach-nhiem', 'tuyen-bo-mien-tru', 'tuyen-bo-mien-trach-nhiem'],
        'terms' => ['terms', 'terms-of-use', 'terms-of-service', 'terms-and-conditions', 'dieu-khoan', 'dieu-khoan-su-dung', 'dieu-kien-su-dung'],
        'contact' => ['contact', 'contact-us', 'lien-he', 'thong-tin-lien-he'],
        'about' => ['about', 'about-us', 'gioi-thieu', 've-chung-toi', 've-chung-minh'],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return list<string> */
    public static function linkedTypes(): array
    {
        return array_values(array_diff(self::types(), ['home']));
    }

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    public static function classify(string $url, string $linkText = ''): ?string
    {
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $normalizedPath = self::normalize(trim($path, '/'));
        if ($normalizedPath === '') {
            return 'home';
        }

        $segments = array_values(array_filter(explode('/', $normalizedPath)));
        $haystacks = array_merge($segments, [$normalizedPath, self::normalize($linkText)]);
        foreach (self::SLUGS as $type => $slugs) {
            foreach ($slugs as $slug) {
                foreach ($haystacks as $haystack) {
                    if ($haystack === $slug || str_contains($haystack, $slug)) {
                        return $type;
                    }
                }
            }
        }

        return null;
    }

    public static function normalize(string $value): string
    {
        $value = Str::ascii(mb_strtolower($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-');
    }
}
