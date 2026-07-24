<?php

namespace Tests\Unit;

use App\Data\CrawlPlan;
use PHPUnit\Framework\TestCase;

final class CrawlPlanTest extends TestCase
{
    public function test_it_deduplicates_urls_and_marks_a_truncated_plan(): void
    {
        $plan = new CrawlPlan(limit: 2, configuredLimit: 2);

        $this->assertTrue($plan->addUrl('https://example.com/', 'start_url'));
        $this->assertFalse($plan->addUrl('https://example.com/', 'sitemap'));
        $this->assertTrue($plan->addUrl('https://example.com/article', 'sitemap'));
        $this->assertFalse($plan->addUrl('https://example.com/another', 'sitemap'));

        $this->assertSame(2, $plan->count());
        $this->assertTrue($plan->truncated);
    }

    public function test_discovery_confidence_does_not_treat_a_lone_homepage_as_full_coverage(): void
    {
        $plan = new CrawlPlan(limit: 100, configuredLimit: 0);
        $plan->addUrl('https://example.com/', 'start_url');
        $this->assertSame('low', $plan->discoveryConfidence());

        $plan->addUrl('https://example.com/article', 'internal_link');
        $this->assertSame('medium', $plan->discoveryConfidence());

        $plan->sitemapFiles = 1;
        $this->assertSame('high', $plan->discoveryConfidence());
    }

    public function test_an_intentional_sample_is_not_a_truncated_discovery(): void
    {
        $plan = new CrawlPlan(limit: 2, configuredLimit: 2);
        $plan->configureSelection('latest_posts', 100, 110, true);

        $this->assertTrue($plan->addUrl('https://example.com/latest', 'sitemap'));
        $this->assertTrue($plan->addUrl('https://example.com/second', 'sitemap'));
        $this->assertFalse($plan->addUrl('https://example.com/third', 'sitemap'));
        $this->assertFalse($plan->truncated);
        $this->assertSame(2.0, $plan->metadata(2)['site_coverage_percent']);
    }

    public function test_parallel_batch_is_a_fixed_plan_that_does_not_expand_internal_links(): void
    {
        $plan = new CrawlPlan(limit: 10, configuredLimit: 10);
        $plan->configureSelection('parallel_batch', 2, 2, true);
        $plan->addUrl('https://example.com/one', 'parallel_batch');
        $plan->addUrl('https://example.com/two', 'parallel_batch');

        $this->assertTrue($plan->usesFixedSitemapSample());
        $this->assertSame(2, $plan->count());
        $this->assertSame('parallel_batch', $plan->selectionMode);
    }
}
