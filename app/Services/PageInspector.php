<?php

namespace App\Services;

use App\Data\CrawlResponse;
use App\Data\PageDocument;
use App\Support\EssentialPublisherPages;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class PageInspector
{
    public function inspect(CrawlResponse $response): PageDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->body, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $title = trim((string) ($xpath->query('//title')->item(0)?->textContent ?? ''));
        $canonical = $xpath->query('//link[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"canonical")]/@href')->item(0)?->nodeValue;
        $language = $xpath->query('/html/@lang')->item(0)?->nodeValue;
        $bodyClass = $xpath->query('//body/@class')->item(0)?->nodeValue;
        $h1Count = $xpath->query('//h1')->length;
        $author = $this->firstAttribute($xpath, [
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="author"]/@content',
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:author"]/@content',
            '//a[contains(translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"author")]/text()',
            '//a[contains(translate(@href,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"/author/")]/text()',
            '//*[contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"author") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"byline")]//text()',
        ]);
        $publishedAt = $this->firstAttribute($xpath, [
            '//meta[translate(@property,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="article:published_time"]/@content',
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="date"]/@content',
            '//time[@datetime]/@datetime',
        ]);
        $structuredData = $this->structuredData($xpath);
        $author ??= $structuredData['author'];
        $publishedAt ??= $structuredData['published_at'];

        $links = [];
        $linksWithText = [];
        foreach ($xpath->query('//a[@href]') as $node) {
            if ($node instanceof DOMElement) {
                $href = $node->getAttribute('href');
                $links[] = $href;
                $linksWithText[] = [
                    'href' => $href,
                    'text' => trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? ''),
                ];
            }
        }

        $images = [];
        foreach ($xpath->query('//img[@src]') as $node) {
            if ($node instanceof DOMElement) {
                $images[] = [
                    'src' => $node->getAttribute('src'),
                    'alt' => trim($node->getAttribute('alt')),
                ];
            }
        }

        $adQuery = '//*[contains(concat(" ", normalize-space(@class), " "), " adsbygoogle ") or @data-ad-client or @data-ad-slot or contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"ad-slot") or contains(translate(@src,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"pagead2.googlesyndication.com") or contains(translate(@src,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"googleads.g.doubleclick.net") or (self::meta and translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="google-adsense-account") or (self::script and (contains(text(),"ca-pub-") or contains(text(),"adsbygoogle")))]';
        $adCount = $xpath->query($adQuery)->length;

        $consentQuery = '//*[contains(translate(@id,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"cookie") or contains(translate(@class,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"consent") or @data-cmp]';
        $hasConsentSignal = $xpath->query($consentQuery)->length > 0;

        foreach ($xpath->query('//script|//style|//noscript|//template|//svg') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $bodyText = $xpath->query('//body')->item(0)?->textContent ?? $dom->textContent;
        $text = trim(preg_replace('/\s+/u', ' ', (string) $bodyText) ?? '');
        preg_match_all('/[\p{L}\p{N}]+(?:[\'’_-][\p{L}\p{N}]+)*/u', $text, $words);

        $linkedTypes = [];
        foreach ($linksWithText as $link) {
            $type = EssentialPublisherPages::classify($link['href'], $link['text']);
            if ($type !== null) {
                $linkedTypes[$type] = true;
            }
        }
        $externalImages = 0;
        $externalImageUrls = [];
        $externalLinkHosts = [];
        $missingAlt = 0;
        $host = strtolower((string) parse_url($response->url, PHP_URL_HOST));
        foreach ($links as $link) {
            $linkHost = strtolower((string) parse_url($link, PHP_URL_HOST));
            if ($linkHost !== '' && $linkHost !== $host) {
                $externalLinkHosts[] = $linkHost;
            }
        }
        foreach ($images as $image) {
            $imageHost = strtolower((string) parse_url($image['src'], PHP_URL_HOST));
            if ($imageHost !== '' && $imageHost !== $host) {
                $externalImages++;
                $externalImageUrls[] = $image['src'];
            }
            if ($image['alt'] === '') {
                $missingAlt++;
            }
        }

        $normalizedText = mb_strtolower($text);
        $authorshipClaims = array_values(array_filter([
            preg_match('/\b(?:100%\s+human|written\s+(?:entirely\s+)?by\s+humans?|human[- ]written)\b/u', $normalizedText) === 1
                || preg_match('/(?:100%\s+do\s+con\s+nguoi|hoan\s+toan\s+do\s+con\s+nguoi)/u', $this->ascii($normalizedText)) === 1
                ? 'human_written_claim' : null,
            preg_match('/\b(?:(?:no|without)\s+(?:use\s+of\s+)?(?:generative\s+)?ai|(?:never|not)\s+(?:use|using)\s+(?:generative\s+)?ai)\b/u', $normalizedText) === 1
                || preg_match('/(?:khong|tuyet\s+doi\s+khong)\s+(?:su\s+dung|dung)\s+ai/u', $this->ascii($normalizedText)) === 1
                ? 'no_ai_claim' : null,
            preg_match('/\b(?:no\s+plagiarism|never\s+plagiarized|100%\s+original)\b/u', $normalizedText) === 1
                || preg_match('/(?:khong|tuyet\s+doi\s+khong)\s+dao\s+van/u', $this->ascii($normalizedText)) === 1
                ? 'originality_claim' : null,
        ]));
        $institutionNames = ['yale', 'harvard', 'oxford', 'mit', 'cambridge', 'stanford', 'princeton'];
        $institutionReferences = [];
        $trustEvidence = mb_strtolower($text.' '.implode(' ', array_column($images, 'alt')).' '.implode(' ', $externalLinkHosts));
        foreach ($institutionNames as $institution) {
            if (str_contains($trustEvidence, $institution)) {
                $institutionReferences[] = $institution;
            }
        }

        return new PageDocument(
            url: $response->url,
            statusCode: $response->status,
            html: $response->body,
            text: $text,
            title: $title,
            canonicalUrl: is_string($canonical) && $canonical !== '' ? $canonical : null,
            language: is_string($language) && $language !== '' ? $language : null,
            wordCount: count($words[0]),
            adCount: $adCount,
            h1Count: $h1Count,
            links: array_values(array_unique($links)),
            images: $images,
            meta: [
                'paragraph_count' => $xpath->query('//p')->length,
                'has_privacy_link' => isset($linkedTypes['privacy']),
                'has_about_link' => isset($linkedTypes['about']),
                'has_contact_link' => isset($linkedTypes['contact']),
                'has_consent_signal' => $hasConsentSignal,
                'external_images' => $externalImages,
                'external_image_urls' => array_values(array_unique($externalImageUrls)),
                'external_link_hosts' => array_slice(array_values(array_unique($externalLinkHosts)), 0, 30),
                'images_missing_alt' => $missingAlt,
                'author' => $author !== null ? mb_substr($author, 0, 160) : null,
                'published_at' => $publishedAt !== null ? mb_substr($publishedAt, 0, 80) : null,
                'authorship_claims' => $authorshipClaims,
                'institution_references' => array_values(array_unique($institutionReferences)),
                'body_class' => is_string($bodyClass) ? trim($bodyClass) : '',
                'links_with_text' => $linksWithText,
            ],
        );
    }

    /** @param list<string> $queries */
    private function firstAttribute(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $value = trim((string) ($xpath->query($query)->item(0)?->nodeValue ?? ''));
            if ($value !== '') {
                return preg_replace('/\s+/u', ' ', $value) ?: $value;
            }
        }

        return null;
    }

    private function ascii(string $value): string
    {
        return mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
    }

    /** @return array{author: ?string, published_at: ?string} */
    private function structuredData(DOMXPath $xpath): array
    {
        $author = null;
        $publishedAt = null;
        foreach ($xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]') as $node) {
            $decoded = json_decode(trim((string) $node->textContent), true);
            if (! is_array($decoded)) {
                continue;
            }
            $items = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $candidate = $item['author'] ?? null;
                if ($author === null && is_string($candidate)) {
                    $author = trim($candidate);
                } elseif ($author === null && is_array($candidate)) {
                    if (is_string($candidate['name'] ?? null)) {
                        $author = trim($candidate['name']);
                    } elseif (is_string($candidate[0] ?? null)) {
                        $author = trim($candidate[0]);
                    } elseif (is_array($candidate[0] ?? null) && is_string($candidate[0]['name'] ?? null)) {
                        $author = trim($candidate[0]['name']);
                    }
                }
                if ($publishedAt === null && is_string($item['datePublished'] ?? null)) {
                    $publishedAt = trim((string) $item['datePublished']);
                }
            }
        }

        return [
            'author' => $author !== '' ? $author : null,
            'published_at' => $publishedAt !== '' ? $publishedAt : null,
        ];
    }
}
