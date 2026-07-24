<?php

namespace Tests\Unit;

use App\Services\RobotsPolicy;
use PHPUnit\Framework\TestCase;

final class RobotsPolicyTest extends TestCase
{
    public function test_it_extracts_declared_sitemaps_and_applies_disallow_rules(): void
    {
        $robots = RobotsPolicy::fromText(<<<'ROBOTS'
        Sitemap: https://example.com/sitemap_index.xml
        Sitemap: https://example.com/wp-sitemap.xml
        User-agent: *
        Disallow: /private/
        ROBOTS);

        $this->assertSame([
            'https://example.com/sitemap_index.xml',
            'https://example.com/wp-sitemap.xml',
        ], $robots->sitemaps());
        $this->assertFalse($robots->allows('https://example.com/private/article'));
        $this->assertTrue($robots->allows('https://example.com/public/article'));
    }
}
