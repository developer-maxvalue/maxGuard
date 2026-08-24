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
        $publisherContextType = EssentialPublisherPages::classify($response->url)
            ?? (preg_match('~(?:^|/)authors?(?:/|$)~i', (string) parse_url($response->url, PHP_URL_PATH)) === 1 ? 'author' : 'content');
        $contentStructure = $this->contentStructure($xpath);
        $trustContextSignals = $this->trustContextSignals($xpath, $publisherContextType);

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

        $publisherClaims = in_array($publisherContextType, ['about', 'disclaimer', 'editorial', 'author'], true)
            ? $this->publisherClaims($text, $publisherContextType)
            : [];
        $authorshipClaims = array_values(array_unique(array_column(array_filter(
            $publisherClaims,
            fn (array $claim): bool => in_array($claim['type'], ['human_written_claim', 'no_ai_claim', 'originality_claim', 'expert_written_claim'], true)
        ), 'type')));
        $institutionReferences = array_values(array_unique(array_column($trustContextSignals, 'institution')));
        $sensitiveAnalysis = $this->sensitiveTopicAndPresentation($title, $text);

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
                'publisher_claims' => $publisherClaims,
                'publisher_context_type' => $publisherContextType,
                'institution_references' => array_values(array_unique($institutionReferences)),
                'trust_context_signals' => $trustContextSignals,
                'content_structure' => $contentStructure,
                'analysis_excerpt' => mb_substr($text, 0, 600),
                'sensitive_topics' => $sensitiveAnalysis['topics'],
                'presentation_styles' => $sensitiveAnalysis['styles'],
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

    /** @return array<string, mixed> */
    private function contentStructure(DOMXPath $xpath): array
    {
        $headingLevels = [];
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') as $heading) {
            $headingLevels[] = strtolower($heading->nodeName);
            if (count($headingLevels) >= 30) {
                break;
            }
        }

        $paragraphBuckets = [];
        foreach ($xpath->query('//article//p|//main//p|//body//p') as $paragraph) {
            $length = mb_strlen(trim((string) $paragraph->textContent));
            $paragraphBuckets[] = match (true) {
                $length < 80 => 'short',
                $length < 240 => 'medium',
                default => 'long',
            };
            if (count($paragraphBuckets) >= 40) {
                break;
            }
        }

        $signatureSource = implode(',', $headingLevels).'|'.implode(',', $paragraphBuckets);

        return [
            'heading_levels' => $headingLevels,
            'heading_count' => count($headingLevels),
            'paragraph_length_buckets' => $paragraphBuckets,
            'paragraph_count_sampled' => count($paragraphBuckets),
            'signature' => $signatureSource === '|' ? null : substr(hash('sha256', $signatureSource), 0, 20),
        ];
    }

    /** @return list<array{type: string, quote: string, page_context: string}> */
    private function publisherClaims(string $text, string $pageContext): array
    {
        $normalized = mb_strtolower($text);
        $ascii = $this->ascii($normalized);
        $patterns = [
            'human_written_claim' => ['/\b(?:100%\s+human|written\s+(?:entirely\s+)?by\s+humans?|human[- ]written)\b/u', '/(?:100%\s+do\s+con\s+nguoi|hoan\s+toan\s+do\s+con\s+nguoi)/u'],
            'no_ai_claim' => ['/\b(?:(?:no|without)\s+(?:use\s+of\s+)?(?:generative\s+)?ai|(?:never|not)\s+(?:use|using)\s+(?:generative\s+)?ai)\b/u', '/(?:khong|tuyet\s+doi\s+khong)\s+(?:su\s+dung|dung)\s+ai/u'],
            'originality_claim' => ['/\b(?:no\s+plagiarism|never\s+plagiarized|100%\s+original|entirely\s+original)\b/u', '/(?:khong|tuyet\s+doi\s+khong)\s+dao\s+van|100%\s+nguyen\s+ban/u'],
            'expert_written_claim' => ['/\b(?:written|reviewed|created)\s+by\s+(?:qualified\s+)?experts?\b/u', '/(?:duoc|do)\s+(?:cac\s+)?chuyen\s+gia\s+(?:viet|bien\s+soan|kiem\s+duyet)/u'],
            'trusted_by_claim' => ['/\btrusted\s+by\b/u', '/\bduoc\s+tin\s+tuong\s+boi\b/u'],
            'partner_claim' => ['/\b(?:our\s+)?partners?\b/u', '/\bdoi\s+tac\b/u'],
            'featured_in_claim' => ['/\bfeatured\s+in\b/u', '/\bduoc\s+gioi\s+thieu\s+tren\b/u'],
            'certified_by_claim' => ['/\bcertified\s+by\b/u', '/\bduoc\s+chung\s+nhan\s+boi\b/u'],
        ];

        $claims = [];
        foreach ($patterns as $type => [$unicodePattern, $asciiPattern]) {
            $matched = null;
            if (preg_match($unicodePattern, $normalized, $match) === 1) {
                $matched = (string) $match[0];
            } elseif (preg_match($asciiPattern, $ascii, $match) === 1) {
                $matched = (string) $match[0];
            }
            if ($matched !== null) {
                $claims[] = ['type' => $type, 'quote' => mb_substr($matched, 0, 240), 'page_context' => $pageContext];
            }
        }

        return $claims;
    }

    /** @return list<array<string, string>> */
    private function trustContextSignals(DOMXPath $xpath, string $pageContext): array
    {
        $institutions = ['yale', 'harvard', 'oxford', 'mit', 'cambridge', 'stanford', 'princeton'];
        $signals = [];
        $seen = [];
        foreach ($xpath->query('//img|//a|//h1|//h2|//h3|//h4|//p') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $element = strtolower($node->tagName);
            $alt = $element === 'img' ? trim($node->getAttribute('alt')) : '';
            $src = $element === 'img' ? trim($node->getAttribute('src')) : '';
            $link = $element === 'a' ? trim($node->getAttribute('href')) : '';
            if ($element === 'img' && $node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'a') {
                $link = trim($node->parentNode->getAttribute('href'));
            }
            $ownText = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            $sectionHeading = trim((string) ($xpath->query('(preceding::h1|preceding::h2|preceding::h3)[last()]', $node)->item(0)?->textContent ?? ''));
            $parentText = trim(preg_replace('/\s+/u', ' ', (string) ($node->parentNode?->textContent ?? '')) ?? '');
            $surrounding = mb_substr(trim($sectionHeading.' '.$parentText), 0, 320);
            $haystack = $this->ascii(implode(' ', [$ownText, $alt, $src, $link, $surrounding]));
            $claimPhrase = '';
            if (preg_match('/\b(trusted\s+by|partners?|featured\s+in|certified\s+by|duoc\s+tin\s+tuong\s+boi|doi\s+tac|duoc\s+chung\s+nhan\s+boi)\b/u', $haystack, $claimMatch) === 1) {
                $claimPhrase = (string) $claimMatch[1];
            }

            foreach ($institutions as $institution) {
                if (preg_match('/\b'.preg_quote($institution, '/').'\b/u', $haystack) !== 1) {
                    continue;
                }
                $contextType = match (true) {
                    $claimPhrase !== '' => 'trust_claim',
                    $pageContext === 'content' => 'editorial_mention',
                    $element === 'img' => 'unverified_branding',
                    default => 'neutral_reference',
                };
                $key = implode('|', [$institution, $element, $contextType, $link, $claimPhrase]);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $signals[] = [
                    'institution' => $institution,
                    'context_type' => $contextType,
                    'element' => $element,
                    'alt' => mb_substr($alt, 0, 180),
                    'heading' => mb_substr($sectionHeading, 0, 180),
                    'surrounding_text' => mb_substr($surrounding, 0, 320),
                    'link' => mb_substr($link, 0, 500),
                    'claim_phrase' => $claimPhrase,
                    'page_context' => $pageContext,
                ];
                if (count($signals) >= 20) {
                    return $signals;
                }
            }
        }

        return $signals;
    }

    /** @return array{topics: list<string>, styles: list<string>} */
    private function sensitiveTopicAndPresentation(string $title, string $text): array
    {
        $content = $this->ascii($title.' '.$text);
        $titleText = $this->ascii($title);
        $topics = array_keys(array_filter([
            'mental_health' => preg_match('/\b(?:mental\s+(?:health|illness|hospital)|psychiatric|self[- ]harm|suicide|tam\s+than|tu\s+sat)\b/u', $content) === 1,
            'domestic_violence' => preg_match('/\b(?:domestic\s+violence|domestic\s+abuse|bao\s+luc\s+gia\s+dinh)\b/u', $content) === 1,
            'sexual_assault' => preg_match('/\b(?:sexual\s+assault|rape|sexual\s+abuse|cuong\s+hiep|xam\s+hai\s+tinh\s+duc)\b/u', $content) === 1,
            'medical_condition' => preg_match('/\b(?:medical\s+condition|diagnos(?:is|ed)|disease|illness|hospital|benh|chan\s+doan|benh\s+vien)\b/u', $content) === 1,
        ]));
        $styles = array_keys(array_filter([
            'sensational' => preg_match('/\b(?:shock(?:ing|ed)?|horrif(?:ic|ying)|unbelievable|unexpected|soc|kinh\s+hoang|khong\s+the\s+tin|bat\s+ngo)\b/u', $titleText) === 1,
            'clickbait' => preg_match('/\b(?:but\s+then|what\s+happened\s+next|you\s+won.t\s+believe|nhung\s+roi|dieu\s+xay\s+ra\s+tiep\s+theo)\b/u', $titleText) === 1 || str_contains($titleText, '...'),
            'graphic' => preg_match('/\b(?:graphic\s+(?:image|detail)|gore|blood[- ]soaked|mau\s+me|hinh\s+anh\s+am\s+anh)\b/u', $content) === 1,
            'exploitative' => preg_match('/\b(?:victim.s\s+shocking|tragedy\s+you\s+won.t\s+believe|bi\s+kich\s+gay\s+soc)\b/u', $content) === 1,
        ]));

        return ['topics' => $topics, 'styles' => $styles];
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
