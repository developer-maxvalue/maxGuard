<?php

namespace Tests\Unit;

use App\Services\SafeUrlValidator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SafeUrlValidatorTest extends TestCase
{
    /** @dataProvider unsafeUrls */
    public function test_it_blocks_private_and_unsafe_urls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SafeUrlValidator())->publicIps($url);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['file:///etc/passwd'],
            ['http://127.0.0.1/'],
            ['http://10.0.0.1/'],
            ['http://169.254.169.254/latest/meta-data/'],
            ['http://localhost/'],
            ['https://example.com:8443/'],
            ['https://user:password@example.com/'],
        ];
    }
}

