<?php

namespace Tests\Unit;

use App\Services\BrowserAdAuditor;
use ReflectionMethod;
use Tests\TestCase;

final class BrowserAdAuditorTest extends TestCase
{
    public function test_it_converts_rendered_layout_metrics_into_ad_findings(): void
    {
        $method = new ReflectionMethod(BrowserAdAuditor::class, 'toFindings');
        $results = $method->invoke(app(BrowserAdAuditor::class), [[
            'name' => 'mobile',
            'width' => 390,
            'height' => 844,
            'adCount' => 3,
            'nearInteractiveCount' => 2,
            'intrusiveOverlayCount' => 1,
            'misleadingLabelCount' => 1,
            'viewportCoverage' => 0.52,
            'popupAttempts' => 1,
            'adRefreshCount' => 0,
            'samples' => [],
        ]], 0);

        $keys = array_map(fn ($result): string => $result->ruleKey, $results);
        $this->assertContains('ads.browser-accidental-click-risk', $keys);
        $this->assertContains('ads.browser-intrusive-overlay', $keys);
        $this->assertContains('ads.browser-misleading-label', $keys);
        $this->assertContains('ads.browser-viewport-dominance', $keys);
        $this->assertContains('ads.browser-popup-or-refresh', $keys);
    }

    public function test_it_reads_the_node_helper_error_from_stdout(): void
    {
        $method = new ReflectionMethod(BrowserAdAuditor::class, 'processFailureMessage');
        $message = $method->invoke(
            app(BrowserAdAuditor::class),
            json_encode(['ok' => false, 'error' => 'Executable does not exist at /browser/chromium']),
            '',
            1,
        );

        $this->assertSame('Executable does not exist at /browser/chromium', $message);
    }
}
