<?php

namespace Tests\Unit;

use App\Data\CrawlResponse;
use App\Services\PageInspector;
use PHPUnit\Framework\TestCase;

final class PageInspectorTest extends TestCase
{
    public function test_it_extracts_page_signals(): void
    {
        $html = <<<'HTML'
        <html lang="en"><head><title>Example article</title></head><body>
        <h1>Example article</h1><p>This is original readable article text.</p>
        <a href="/privacy">Privacy policy</a><a href="/about">About</a><a href="/contact">Contact</a>
        <script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script>
        <ins class="adsbygoogle" data-ad-client="ca-pub-1"></ins><img src="https://cdn.example.net/photo.jpg" alt="Photo">
        </body></html>
        HTML;

        $page = (new PageInspector())->inspect(new CrawlResponse('https://example.com/', 200, $html));

        $this->assertSame('Example article', $page->title);
        $this->assertSame(1, $page->h1Count);
        $this->assertSame(2, $page->adCount);
        $this->assertTrue($page->meta['has_privacy_link']);
        $this->assertSame(1, $page->meta['external_images']);
        $this->assertSame(['https://cdn.example.net/photo.jpg'], $page->meta['external_image_urls']);
    }
}
