<?php

use App\Detectors\AdExperienceDetector;
use App\Detectors\AdsTxtDetector;
use App\Detectors\ContentQualityDetector;
use App\Detectors\CopyrightSignalsDetector;
use App\Detectors\DuplicateContentDetector;
use App\Detectors\PrivacyDetector;
use App\Detectors\PublisherPolicyPagesDetector;
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
    'page_queue' => env('MAXGUARD_PAGE_QUEUE', 'scan-pages'),
    'finalize_queue' => env('MAXGUARD_FINALIZE_QUEUE', 'scan-finalize'),
    'orchestrator_timeout_seconds' => (int) env('MAXGUARD_ORCHESTRATOR_TIMEOUT', 900),
    'page_job_timeout_seconds' => (int) env('MAXGUARD_PAGE_JOB_TIMEOUT', 1800),
    'finalize_timeout_seconds' => (int) env('MAXGUARD_FINALIZE_TIMEOUT', 900),
    'page_batch_size' => (int) env('MAXGUARD_PAGE_BATCH_SIZE', 10),
    'recommended_page_workers' => (int) env('MAXGUARD_PAGE_WORKERS', 6),
    'worker_memory_mb' => (int) env('MAXGUARD_WORKER_MEMORY', 1024),
    'evidence_disk' => env('MAXGUARD_EVIDENCE_DISK', 'local'),
    'evidence_prefix' => env('MAXGUARD_EVIDENCE_PREFIX', 'maxguard/evidence'),

    'ai' => [
        'enabled' => (bool) env('MAXGUARD_AI_ENABLED', false),
        // OpenAI remains the code-level compatibility default. The distributed
        // MaxGuard env template explicitly selects Gemini for new installs.
        'provider' => env('MAXGUARD_AI_PROVIDER', 'openai'),
        'api_key' => env('GEMINI_API_KEY', env('OPENAI_API_KEY')),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'gemini_base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        // Terra balances policy-review quality, latency and cost. Use gpt-5.6-sol for highest-quality review.
        'model' => env('MAXGUARD_AI_MODEL', 'gemini-2.5-flash'),
        'reasoning_effort' => env('MAXGUARD_AI_REASONING_EFFORT', 'low'),
        'output_language' => env('MAXGUARD_AI_OUTPUT_LANGUAGE', 'Vietnamese'),
        'max_input_chars' => (int) env('MAXGUARD_AI_MAX_INPUT_CHARS', 24_000),
        'max_output_tokens' => (int) env('MAXGUARD_AI_MAX_OUTPUT_TOKENS', 3000),
        // 0 analyzes every crawled page. Keep a cap in production to control cost and scan duration.
        'max_pages_per_scan' => (int) env('MAXGUARD_AI_MAX_PAGES_PER_SCAN', 100),
        'min_confidence' => (int) env('MAXGUARD_AI_MIN_CONFIDENCE', 70),
        'connect_timeout_seconds' => (int) env('MAXGUARD_AI_CONNECT_TIMEOUT', 10),
        'timeout_seconds' => (int) env('MAXGUARD_AI_TIMEOUT', 90),
    ],

    'sightengine' => [
        'enabled' => (bool) env('SIGHTENGINE_ENABLED', false),
        'endpoint' => env('SIGHTENGINE_ENDPOINT', 'https://api.sightengine.com/1.0/text/check.json'),
        'api_user' => env('SIGHTENGINE_API_USER'),
        'api_secret' => env('SIGHTENGINE_API_SECRET'),
        'models' => env('SIGHTENGINE_MODELS', 'general,self-harm'),
        'violation_threshold' => (float) env('SIGHTENGINE_VIOLATION_THRESHOLD', 0.55),
        'max_input_chars' => (int) env('SIGHTENGINE_MAX_INPUT_CHARS', 20000),
        'connect_timeout_seconds' => (int) env('SIGHTENGINE_CONNECT_TIMEOUT', 10),
        'timeout_seconds' => (int) env('SIGHTENGINE_TIMEOUT', 45),
    ],

    // Optional real-browser audit. The Node helper blocks private/reserved
    // network destinations for the main page and every subresource.
    'browser_audit' => [
        'enabled' => (bool) env('MAXGUARD_BROWSER_AUDIT_ENABLED', false),
        'node_binary' => env('MAXGUARD_NODE_BINARY', 'node'),
        'script' => env('MAXGUARD_BROWSER_AUDIT_SCRIPT', base_path('scripts/browser-ad-audit.mjs')),
        'max_pages_per_scan' => (int) env('MAXGUARD_BROWSER_MAX_PAGES_PER_SCAN', 50),
        'timeout_seconds' => (int) env('MAXGUARD_BROWSER_TIMEOUT', 45),
        'settle_ms' => (int) env('MAXGUARD_BROWSER_SETTLE_MS', 2500),
        'proximity_px' => (int) env('MAXGUARD_BROWSER_AD_PROXIMITY_PX', 24),
    ],

    // Optional off-site copy discovery using Tavily Search.
    // Search snippets identify candidates; MaxGuard fetches candidates through
    // SafeHttpClient and calculates its own shingle containment score.
    'external_copy' => [
        'enabled' => (bool) env('MAXGUARD_EXTERNAL_COPY_ENABLED', false),
        'api_key' => env('TAVILY_API_KEY'),
        'endpoint' => env('TAVILY_SEARCH_ENDPOINT', 'https://api.tavily.com/search'),
        'max_pages_per_scan' => (int) env('MAXGUARD_EXTERNAL_COPY_MAX_PAGES_PER_SCAN', 30),
        'queries_per_page' => (int) env('MAXGUARD_EXTERNAL_COPY_QUERIES_PER_PAGE', 2),
        'candidates_per_query' => (int) env('MAXGUARD_EXTERNAL_COPY_CANDIDATES_PER_QUERY', 5),
        'minimum_words' => (int) env('MAXGUARD_EXTERNAL_COPY_MIN_WORDS', 250),
        'review_threshold' => (float) env('MAXGUARD_EXTERNAL_COPY_REVIEW_THRESHOLD', 0.35),
        'high_threshold' => (float) env('MAXGUARD_EXTERNAL_COPY_HIGH_THRESHOLD', 0.65),
        'timeout_seconds' => (int) env('MAXGUARD_EXTERNAL_COPY_TIMEOUT', 20),
    ],

    'ga4' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'traffic_days' => (int) env('MAXGUARD_GA4_TRAFFIC_DAYS', 7),
        'max_rows' => (int) env('MAXGUARD_GA4_MAX_ROWS', 1000),
    ],

    'crawler' => [
        'user_agent' => env('MAXGUARD_USER_AGENT', 'MaxGuard-ComplianceBot/1.0 (+https://example.com/bot)'),
        // 0 means all discovered URLs, still bounded by max_discovered_urls.
        'max_pages' => (int) env('MAXGUARD_MAX_PAGES', 0),
        'max_discovered_urls' => (int) env('MAXGUARD_MAX_DISCOVERED_URLS', 100_000),
        'max_sitemaps' => (int) env('MAXGUARD_MAX_SITEMAPS', 1000),
        'follow_internal_links' => (bool) env('MAXGUARD_FOLLOW_INTERNAL_LINKS', true),
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
        PublisherPolicyPagesDetector::class,
        TechnicalTrustDetector::class,
    ],

    'thresholds' => [
        'thin_content_words' => (int) env('MAXGUARD_THIN_CONTENT_WORDS', 300),
        'low_value_words' => (int) env('MAXGUARD_LOW_VALUE_WORDS', 600),
        'duplicate_similarity' => (float) env('MAXGUARD_DUPLICATE_SIMILARITY', 0.86),
        'duplicate_sketch_size' => (int) env('MAXGUARD_DUPLICATE_SKETCH_SIZE', 128),
        'duplicate_candidate_limit' => (int) env('MAXGUARD_DUPLICATE_CANDIDATE_LIMIT', 30),
        'duplicate_bucket_limit' => (int) env('MAXGUARD_DUPLICATE_BUCKET_LIMIT', 200),
        'max_ads_per_page' => (int) env('MAXGUARD_MAX_ADS_PER_PAGE', 6),
        'min_words_per_ad' => (int) env('MAXGUARD_MIN_WORDS_PER_AD', 220),
        'ad_page_empty_content_words' => (int) env('MAXGUARD_AD_PAGE_EMPTY_CONTENT_WORDS', 80),
        'ad_page_thin_content_words' => (int) env('MAXGUARD_AD_PAGE_THIN_CONTENT_WORDS', 300),
    ],
];
