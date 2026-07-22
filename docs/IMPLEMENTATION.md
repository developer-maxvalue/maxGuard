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
    └── Evidence packaging ── S3-compatible object storage
```

Phần HTML crawler, detector, queue và evidence đã có trong gói. Browser rendering nâng cao nên là service riêng dùng Playwright/Chromium. Laravel chịu trách nhiệm orchestration, quyền truy cập, queue, dữ liệu và UI; không nên chạy Chromium trong PHP-FPM.

## 2. Domain model tối thiểu

### `websites`

- `id`, `user_id`, `domain`, `start_url`.
- `status`, `overall_score`, `last_scanned_at`.
- `expected_monthly_revenue`, `pages_count`, `open_findings_count`.
- `ownership_verified_at`, `settings` JSON.

### `scans`

- `id`, `website_id`, `type`, `status`, `progress`.
- `requested_by`, `started_at`, `finished_at`.
- `pages_discovered`, `pages_scanned`, `risk_score`.
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

## 4. Copyright và duplicate content

Backend hiện có internal near-duplicate và media provenance signals. Để nâng cấp thành hệ thống copyright chuyên sâu, bổ sung:

1. **Cross-domain near-duplicate text**: index nguồn ngoài, SimHash/MinHash và source precedence.
2. **Source precedence**: so sánh thời gian index/publish và canonical để tránh kết luận ngược.
3. **Media provenance**: perceptual hash, EXIF, license record, attribution và nguồn upload.
4. **Original value**: tỷ lệ nội dung gốc, reporting, commentary, first-hand evidence và cấu trúc biên tập.

Không tự động kết luận “vi phạm bản quyền” chỉ từ similarity. UI phải hiển thị “potential infringement/review required” cho đến khi evidence đủ mạnh hoặc con người xác nhận.

## 5. Queue và giới hạn tài nguyên

MVP dùng queue `scans` với job `RunWebsiteScan`. Khi tải lớn, tách queue theo workload:

- `scans`: orchestration.
- `fetch`: HTTP I/O.
- `render`: Playwright, RAM/CPU cao.
- `detectors`: NLP/image similarity.
- `evidence`: nén, hash, upload.
- `notifications`: email/webhook.

Cấu hình Horizon supervisor riêng cho `render`, giới hạn số process để tránh hết RAM. Khi tách job, mọi job cần:

- Idempotency key.
- Timeout và retry có backoff.
- Distributed lock theo scan/page.
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

```dotenv
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

MAXGUARD_EVIDENCE_DISK=s3
MAXGUARD_QUEUE=scans
MAXGUARD_MAX_PAGES=100
MAXGUARD_HOST_RPS=1.5
AWS_BUCKET=maxguard-evidence
AWS_USE_PATH_STYLE_ENDPOINT=false

MAXGUARD_ROUTE_MIDDLEWARE=auth
MAXGUARD_PROVIDE_AUTH_ROUTES=true
```

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
- Database, queue, dashboard, findings và private evidence download.

### Bổ sung trước khi bán SaaS đa tenant

- Workspace/membership scopes, policies, audit log và notification.
- Playwright renderer, screenshot, mobile ad/CMP checks theo region.
- External similarity index, media provenance và invalid-traffic ingestion.
- Retention policy, signed export package và billing limits.

### Hardening production

- Load/security tests, false-positive review.
- Detector/ruleset versioning, observability, billing limits.
- Backup/restore drill và incident runbook.
