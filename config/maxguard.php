<?php

use App\Detectors\AdExperienceDetector;
use App\Detectors\AdsTxtDetector;
use App\Detectors\ContentQualityDetector;
use App\Detectors\CopyrightSignalsDetector;
use App\Detectors\DuplicateContentDetector;
use App\Detectors\PrivacyDetector;
use App\Detectors\TechnicalTrustDetector;

return [
    // Set this to false when the host project already provides Breeze,
    // Jetstream, Fortify or another authentication implementation.
    'provide_auth_routes' => (bool) env('MAXGUARD_PROVIDE_AUTH_ROUTES', true),

    'route_middleware' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('MAXGUARD_ROUTE_MIDDLEWARE', 'auth'))
    ))),

    'queue' => env('MAXGUARD_QUEUE', 'scans'),
    'evidence_disk' => env('MAXGUARD_EVIDENCE_DISK', 'local'),
    'evidence_prefix' => env('MAXGUARD_EVIDENCE_PREFIX', 'maxguard/evidence'),
    'require_ownership_verification' => (bool) env('MAXGUARD_REQUIRE_OWNERSHIP', true),

    'crawler' => [
        'user_agent' => env('MAXGUARD_USER_AGENT', 'MaxGuard-ComplianceBot/1.0 (+https://example.com/bot)'),
        'max_pages' => (int) env('MAXGUARD_MAX_PAGES', 100),
        'max_redirects' => (int) env('MAXGUARD_MAX_REDIRECTS', 4),
        'timeout_seconds' => (int) env('MAXGUARD_TIMEOUT', 20),
        'connect_timeout_seconds' => (int) env('MAXGUARD_CONNECT_TIMEOUT', 8),
        'max_response_bytes' => (int) env('MAXGUARD_MAX_RESPONSE_BYTES', 5_000_000),
        'requests_per_second' => (float) env('MAXGUARD_HOST_RPS', 1.5),
        'respect_robots' => (bool) env('MAXGUARD_RESPECT_ROBOTS', true),
    ],

    'detectors' => [
        ContentQualityDetector::class,
        DuplicateContentDetector::class,
        CopyrightSignalsDetector::class,
        AdExperienceDetector::class,
        AdsTxtDetector::class,
        PrivacyDetector::class,
        TechnicalTrustDetector::class,
    ],

    'thresholds' => [
        'thin_content_words' => (int) env('MAXGUARD_THIN_CONTENT_WORDS', 300),
        'low_value_words' => (int) env('MAXGUARD_LOW_VALUE_WORDS', 600),
        'duplicate_similarity' => (float) env('MAXGUARD_DUPLICATE_SIMILARITY', 0.86),
        'max_ads_per_page' => (int) env('MAXGUARD_MAX_ADS_PER_PAGE', 6),
        'min_words_per_ad' => (int) env('MAXGUARD_MIN_WORDS_PER_AD', 220),
    ],
];
