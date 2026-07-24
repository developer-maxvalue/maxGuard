<?php

namespace Tests\Unit;

use App\Services\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    /** WordPress canonical trailing slashes must survive normalization. */
    public function test_it_preserves_a_trailing_slash(): void
    {
        $normalizer = new UrlNormalizer();

        $this->assertSame(
            'https://example.com/article/',
            $normalizer->normalize('https://EXAMPLE.com/article/'),
        );
        $this->assertSame('https://example.com/', $normalizer->normalize('https://example.com/'));
    }
}
