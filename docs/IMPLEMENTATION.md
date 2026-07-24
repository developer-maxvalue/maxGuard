# Kiến trúc triển khai MaxGuard trên Laravel

## 1. Kiến trúc production khuyến nghị

```text
Nginx / CDN
    │
Laravel 10 Web/API ── PostgreSQL/MySQL
    │                    │
    ├── Redis Cache      ├── Websites / Scans / Findings
    ├── Redis Queue      └── Evidence metadata
    │
Laravel Horizon Workers
    ├── URL discovery + safe HTTP fetch
    ├── HTML inspection
    ├── Policy detectors
    ├── OpenAI semantic policy analysis
    └── Evidence packaging ── S3-compatible object storage
```

Phần HTML crawler, detector, queue và evidence đã có trong gói. Browser rendering nâng cao nên là service riêng dùng Playwright/Chromium. Laravel chịu trách nhiệm orchestration, quyền truy cập, queue, dữ liệu và UI; không nên chạy Chromium trong PHP-FPM.

## 2. Domain model tối thiểu

### `websites`

- `id`, `user_id`, `domain`, `start_url`.
- `status`, `overall_score`, `last_scanned_at`.
- `expected_monthly_revenue`, `pages_count`, `open_findings_count`.
- `settings` JSON cho cấu hình crawl theo website.

### `scans`

- `id`, `website_id`, `type`, `status`, `progress`, `max_urls`.
- `requested_by`, `started_at`, `finished_at`.
- `pages_discovered`, `pages_scanned`, `pages_skipped_unchanged`, `current_url`, `risk_score`.
- `use_ai`, `force_rescan`, `ai_pages_analyzed`, `ai_findings_count`.
- `ruleset_version`, `error_message`, `meta` JSON.

### `pages`

- URL canonical, HTTP status, title, language, content hash.
- Content hash, word/ad count, canonical URL và private snapshot path.
- Metadata tín hiệu được trích từ HTML.

### `findings`

- `website_id`, `scan_id`, `page_id`, `rule_key`.
- `category`, `severity`, `confidence`, `status`.
- `title`, `summary`, `policy_reference`, `revenue_impact`.
- `first_seen_at`, `last_seen_at`, `resolved_at`, `assigned_to`.

### `evidence_items`

- `finding_id`, `type`, `disk`, `path`, `sha256`.
- `captured_at`, `source_url`, `metadata` JSON.
- Giữ immutable; khi scan lại thì tạo evidence version mới.

Remediation checklist hiện được lưu trong `findings.remediation` JSON. Nếu cần assignment/SLA phức tạp, tách thành bảng `remediation_tasks` ở giai đoạn mở rộng.

## 3. Rules engine

Không hard-code toàn bộ policy trong controller. Dùng contract:

```php
interface Detector
{
    public function key(): string;
    public function detect(PageDocument $page): array;
}
```

Mỗi detector trả về `DetectorResult`; `RiskScoreCalculator` tổng hợp severity dựa trên:

- Mức độ chắc chắn của evidence.
- Phạm vi: một URL, nhiều URL hay site-wide.
- Khả năng tác động tới tài khoản/kiếm tiền.
- Lịch sử tái phạm.
- Giá trị doanh thu bị phơi nhiễm.

Lưu `ruleset_version` theo từng scan để audit kết quả về sau. Khi thay logic detector, tăng version này trong `ScanDispatcher`.

## 3.1. AI policy analyzer

`AiPolicyAnalyzer` là lớp semantic review sau deterministic detector. Mỗi page request dùng OpenAI Responses API, `store=false` và Structured Outputs với schema strict. Model chỉ được chọn một số `policy_code` cố định; category và policy reference được map lại phía server, không tin trực tiếp chuỗi model tạo ra.

Các guardrail chính:

- Chỉ chạy khi scan có `use_ai=true` và server có key.
- Page text bị truncate theo `MAXGUARD_AI_MAX_INPUT_CHARS`.
- Prompt coi toàn bộ nội dung website là untrusted data và cấm làm theo instruction trong bài.
- Findings dưới `MAXGUARD_AI_MIN_CONFIDENCE` bị loại.
- AI error không làm hỏng deterministic scan.
- Rules-only scan không resolve finding `ai.*`.
- AI finding cũ chỉ được resolve khi đúng URL đã được AI phân tích lại thành công.
- `MAXGUARD_AI_MAX_PAGES_PER_SCAN` giới hạn chi phí và latency.

## 3.2. Incremental analysis cache

`pages.content_hash` là SHA-256 của text đã normalize. Sau một lần phân tích thành công, `pages.meta.maxguard_analysis` lưu `ruleset_version`, `scan_type`, trạng thái AI, AI model và thời điểm phân tích. Scan sau chỉ tái sử dụng khi hash và toàn bộ coverage yêu cầu tương thích.

Luồng cache an toàn:

1. Fetch trang và tính content hash mới.
2. Nếu hash/coverage không tương thích hoặc `force_rescan=true`, chạy detector và AI bình thường.
3. Nếu tương thích, chỉ cập nhật crawl metadata/`last_scan_id`, warm fingerprint cho duplicate comparison và bỏ qua detector, AI, snapshot, evidence.
4. Không đưa page được tái sử dụng vào tập auto-resolve; finding cũ vì vậy không bị resolve nhầm.
5. Chỉ ghi analysis marker sau khi detector/evidence/finding persistence hoàn tất.

Cơ chế này tiết kiệm phần tốn CPU/chi phí nhất nhưng vẫn phát hiện bài viết bị sửa trên cùng URL. Một hệ thống chỉ kiểm tra “URL đã từng tồn tại” rồi bỏ qua vĩnh viễn là không an toàn cho compliance monitoring.

## 4. Copyright và duplicate content

Backend hiện có internal near-duplicate và media provenance signals. Để nâng cấp thành hệ thống copyright chuyên sâu, bổ sung:

1. **Cross-domain near-duplicate text**: index nguồn ngoài, SimHash/MinHash và source precedence.
2. **Source precedence**: so sánh thời gian index/publish và canonical để tránh kết luận ngược.
3. **Media provenance**: perceptual hash, EXIF, license record, attribution và nguồn upload.
4. **Original value**: tỷ lệ nội dung gốc, reporting, commentary, first-hand evidence và cấu trúc biên tập.

Không tự động kết luận “vi phạm bản quyền” chỉ từ similarity. UI phải hiển thị “potential infringement/review required” cho đến khi evidence đủ mạnh hoặc con người xác nhận.

## 5. Queue và giới hạn tài nguyên

Pipeline hiện tại đã tách theo workload:

- `scans`: discovery/orchestration.
- `scan-pages`: HTTP, rules, AI và evidence theo batch nhỏ.
- `scan-finalize`: duplicate comparison toàn sample, resolve finding và risk score.
- `fetch`: queue mở rộng nếu sau này tách HTTP khỏi page analyzer.
- `render`: Playwright, RAM/CPU cao.
- `detectors`: NLP/image similarity.
- `evidence`: nén, hash, upload.
- `notifications`: email/webhook.

Cấu hình Horizon supervisor riêng cho `render`, giới hạn số process để tránh hết RAM. Khi tách job, mọi job cần:

- Idempotency key.
- Timeout và retry có backoff.
    - Distributed lock/claim token theo scan/page.
- Dead-letter/failed job alert.
- Rate limit theo host và workspace.

## 6. Evidence và bảo mật

- Evidence object storage phải private, mã hóa at rest và truy cập qua signed URL ngắn hạn.
- Lưu SHA-256 cho mọi file để chứng minh integrity.
- Phân quyền workspace bằng Laravel Policies, không chỉ kiểm tra ID trên route.
- Audit mọi thao tác assign, suppress, resolve, export và delete.
- Escape nội dung crawl trong Blade; không render HTML của website mục tiêu trực tiếp.
- Chặn SSRF: chỉ cho HTTP/HTTPS, resolve DNS an toàn, cấm private/link-local IP và kiểm tra lại sau redirect.
- Không đưa credential của browser renderer vào job payload/log.

## 7. Auth và multi-tenancy

Gói mặc định dùng middleware `auth`, auth route tối thiểu và `websites.user_id`, phù hợp single-owner/single-tenant MVP. Tạo tài khoản đầu tiên bằng `php artisan maxguard:create-admin`. Nếu host app đã có Breeze/Jetstream/Fortify, đặt `MAXGUARD_PROVIDE_AUTH_ROUTES=false` để dùng route auth hiện có. Nếu bán SaaS đa tenant, thêm `workspace_id`, membership và tenant scope trước khi onboard khách hàng. Khuyến nghị Laravel Fortify/Jetstream cho auth nâng cao; Spatie Permission cho role nếu phù hợp giấy phép dự án.

Các role gợi ý:

- Owner: billing, workspace, export/delete.
- Compliance manager: scan, assign, resolve.
- Analyst: review evidence, comment.
- Viewer: read-only reports.

## 8. Cấu hình môi trường

Cấu hình dễ triển khai trên một máy chủ Laravel thông thường:

```dotenv
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=2400
CACHE_DRIVER=file
SESSION_DRIVER=file

MAXGUARD_EVIDENCE_DISK=s3
MAXGUARD_QUEUE=scans
MAXGUARD_PAGE_QUEUE=scan-pages
MAXGUARD_FINALIZE_QUEUE=scan-finalize
MAXGUARD_PAGE_BATCH_SIZE=10
MAXGUARD_PAGE_WORKERS=6
MAXGUARD_ORCHESTRATOR_TIMEOUT=900
MAXGUARD_PAGE_JOB_TIMEOUT=1800
MAXGUARD_FINALIZE_TIMEOUT=900
MAXGUARD_MAX_PAGES=0
MAXGUARD_MAX_DISCOVERED_URLS=100000
MAXGUARD_MAX_SITEMAPS=1000
MAXGUARD_WORKER_MEMORY=1024
MAXGUARD_HOST_RPS=1.5
OPENAI_API_KEY=project-key
MAXGUARD_AI_ENABLED=true
MAXGUARD_AI_MODEL=gpt-5.6-terra
MAXGUARD_AI_MAX_PAGES_PER_SCAN=100
AWS_BUCKET=maxguard-evidence
AWS_USE_PATH_STYLE_ENDPOINT=false

MAXGUARD_ROUTE_MIDDLEWARE=auth
MAXGUARD_PROVIDE_AUTH_ROUTES=true
```

Tạo bảng và kiểm tra queue:

```bash
php artisan migrate
php artisan maxguard:queue-doctor
php artisan queue:work database --queue=scans,scan-finalize --sleep=2 --tries=3 --timeout=900 --memory=1024
php artisan queue:work database --queue=scan-pages --sleep=1 --tries=2 --timeout=1800 --memory=1024
```

Nếu doctor báo thiếu bảng `jobs` và codebase chưa có migration tạo bảng này, chạy `php artisan queue:table && php artisan migrate` rồi kiểm tra lại.

Với database queue, đặt `retry_after` trong `config/queue.php` lớn hơn job timeout để job dài không bị reserve lần hai:

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 2400),
```

Crawler đọc sitemap được khai báo trong robots.txt và các endpoint phổ biến, phân biệt `sitemapindex`/`urlset`, đi đệ quy qua sitemap con rồi bổ sung internal links. `MAXGUARD_MAX_PAGES=0` quét toàn bộ URL phát hiện trong safety cap. Scan thiếu coverage được lưu là `partial`.

Khi scan truyền `max_urls`, crawler chuyển sang fixed sitemap sample: đọc toàn bộ sitemap metadata trước, ưu tiên URL từ `post-sitemap*.xml`, sort theo `<lastmod>` giảm dần và chọn đúng N bài. Internal links không được phép mở rộng fixed sample. Chạm giới hạn do người dùng yêu cầu không phải discovery truncation; scan đủ N URL được ghi `completed` kèm `is_sampled=true`. Safety cap, sitemap error, robots block hoặc selected URL failure vẫn tạo `partial`.

Khi có Redis/Horizon production, đổi `QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`; cấu hình một process cho `scans,scan-finalize` và nhiều process cho `scan-pages`. File Supervisor mẫu nằm tại `docs/supervisor-maxguard.conf.example`.

## 9. Scheduler

Trong Laravel 10, cấu hình trong `app/Console/Kernel.php`:

```php
Schedule::command('maxguard:scan-due-sites')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
// Thêm command prune evidence khi retention policy được chốt.
```

## 10. Deploy production

1. Build immutable release; không sửa code trực tiếp trên server.
2. Document root của Nginx trỏ tới `/public`.
3. Chạy migration ở một release job riêng.
4. Cache config/routes/views sau khi `.env` đã đúng.
5. Restart queue workers sau deploy.

```bash
php artisan migrate --force
php artisan optimize
php artisan horizon:terminate
php artisan queue:restart
```

Health checks cần bao phủ HTTP app, database, Redis, object storage, renderer và queue lag. Cảnh báo khi critical scan thất bại hoặc queue `render` vượt SLA.

## 11. Trạng thái và lộ trình

### Đã có trong gói

- Website CRUD cơ bản, manual/scheduled scan.
- Safe HTML crawler, policy detectors cơ bản.
- Database, queue, dashboard, live findings report, Excel export và private evidence download.
- Semantic AI review tùy chọn, per-scan URL cap và live scanned/discovered/current URL progress.

### Bổ sung trước khi bán SaaS đa tenant

- Workspace/membership scopes, policies, audit log và notification.
- Playwright renderer, screenshot, mobile ad/CMP checks theo region.
- External similarity index, media provenance và invalid-traffic ingestion.
- Retention policy, signed export package và billing limits.

### Hardening production

- Load/security tests, false-positive review.
- Detector/ruleset versioning, observability, billing limits.
- Backup/restore drill và incident runbook.
