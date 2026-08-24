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

        $page = (new PageInspector)->inspect(new CrawlResponse('https://example.com/', 200, $html));

        $this->assertSame('Example article', $page->title);
        $this->assertSame(1, $page->h1Count);
        $this->assertSame(2, $page->adCount);
        $this->assertTrue($page->meta['has_privacy_link']);
        $this->assertSame(1, $page->meta['external_images']);
        $this->assertSame(['https://cdn.example.net/photo.jpg'], $page->meta['external_image_urls']);
    }

    public function test_it_recognizes_vietnamese_publisher_page_links(): void
    {
        $html = <<<'HTML'
        <html><body>
        <a href="/gioi-thieu">Về chúng tôi</a>
        <a href="/lien-he">Liên hệ</a>
        <a href="/chinh-sach-bao-mat">Chính sách bảo mật</a>
        </body></html>
        HTML;

        $page = (new PageInspector)->inspect(new CrawlResponse('https://example.com/', 200, $html));

        $this->assertTrue($page->meta['has_about_link']);
        $this->assertTrue($page->meta['has_contact_link']);
        $this->assertTrue($page->meta['has_privacy_link']);
    }

    public function test_it_extracts_authorship_and_transparency_signals_for_site_wide_review(): void
    {
        $html = <<<'HTML'
        <html><head>
        <title>About us</title>
        <meta name="author" content="impression05">
        <meta property="article:published_time" content="2026-08-24T08:00:00Z">
        </head><body>
        <h1>About us</h1>
        <p>Our stories are 100% human-written. We never use AI and publish 100% original work with no plagiarism.</p>
        <a href="https://www.harvard.edu/">Harvard</a>
        <img src="https://cdn.example.org/yale-logo.png" alt="Yale logo">
        </body></html>
        HTML;

        $page = (new PageInspector)->inspect(new CrawlResponse('https://publisher.example/about', 200, $html));

        $this->assertSame('impression05', $page->meta['author']);
        $this->assertSame('2026-08-24T08:00:00Z', $page->meta['published_at']);
        $this->assertContains('human_written_claim', $page->meta['authorship_claims']);
        $this->assertContains('no_ai_claim', $page->meta['authorship_claims']);
        $this->assertContains('originality_claim', $page->meta['authorship_claims']);
        $this->assertEqualsCanonicalizing(['harvard', 'yale'], $page->meta['institution_references']);
    }
}
