<?php

namespace App\Services;

use App\Data\CrawlResponse;
use App\Data\PageDocument;
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

        $links = [];
        foreach ($xpath->query('//a[@href]') as $node) {
            if ($node instanceof DOMElement) {
                $links[] = $node->getAttribute('href');
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

        $lowerLinks = mb_strtolower(implode(' ', $links));
        $externalImages = 0;
        $externalImageUrls = [];
        $missingAlt = 0;
        $host = strtolower((string) parse_url($response->url, PHP_URL_HOST));
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
                'has_privacy_link' => str_contains($lowerLinks, 'privacy') || str_contains($lowerLinks, 'cookie'),
                'has_about_link' => str_contains($lowerLinks, 'about'),
                'has_contact_link' => str_contains($lowerLinks, 'contact'),
                'has_consent_signal' => $hasConsentSignal,
                'external_images' => $externalImages,
                'external_image_urls' => array_values(array_unique($externalImageUrls)),
                'images_missing_alt' => $missingAlt,
                'body_class' => is_string($bodyClass) ? trim($bodyClass) : '',
            ],
        );
    }
}
