<?php

namespace Tests\Unit;

use App\Data\CrawlResponse;
use App\Detectors\PublisherPolicyPagesDetector;
use App\Services\PageInspector;
use PHPUnit\Framework\TestCase;

final class PublisherPolicyPagesDetectorTest extends TestCase
{
    public function test_it_reports_missing_required_links_on_the_home_page(): void
    {
        $page = (new PageInspector)->inspect(new CrawlResponse(
            'https://example.com/',
            200,
            '<html><body><a href="/about">About</a><a href="/contact">Contact</a></body></html>',
        ));

        $results = (new PublisherPolicyPagesDetector)->detect($page);

        $this->assertCount(1, $results);
        $this->assertSame('publisher.required-pages-missing', $results[0]->ruleKey);
        $this->assertContains('privacy', $results[0]->signals['missing_types']);
    }

    public function test_it_validates_required_google_privacy_disclosures(): void
    {
        $page = (new PageInspector)->inspect(new CrawlResponse(
            'https://example.com/chinh-sach-bao-mat',
            200,
            '<html><body><h1>Chính sách bảo mật</h1><p>Chúng tôi tôn trọng quyền riêng tư của bạn.</p></body></html>',
        ));

        $results = (new PublisherPolicyPagesDetector)->detect($page);

        $this->assertCount(1, $results);
        $this->assertSame('publisher.required-page-incomplete.privacy', $results[0]->ruleKey);
        $this->assertSame('high', $results[0]->severity);
        $this->assertContains('Google, quảng cáo hoặc bên thứ ba', $results[0]->signals['failed_checks']);
    }
}
