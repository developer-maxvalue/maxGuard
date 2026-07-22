# MaxGuard — Laravel 10 Complete Starter

Ứng dụng kiểm tra tuân thủ publisher/AdSense gồm backend Laravel 10 và giao diện Metronic Bootstrap 5. Gói này được thiết kế để **merge vào một dự án Laravel 10 hiện có**; không chứa thư mục `vendor/` hoặc framework Laravel.

## Chức năng đã có

- Database migrations cho websites, scans, pages, findings và evidence items.
- Eloquent models, relationships, scopes và route model binding.
- Tạo website có kiểm tra URL/DNS chống SSRF.
- Xác minh quyền sở hữu bằng file `/.well-known/maxguard-verification.txt` trước khi scan.
- Queue job scan với unique lock, retry, backoff, timeout và trạng thái tiến trình.
- Crawler nội bộ: sitemap, internal links, robots.txt, rate limit, giới hạn số trang/response.
- Redirect được kiểm tra lại từng bước; chặn localhost, private/reserved IP, credential URL và port lạ.
- HTML inspector và bảy detector:
  - content quality;
  - internal duplicate content bằng 4-word Jaccard shingles;
  - media/copyright provenance signals;
  - ad density;
  - ads.txt presence and Google seller entry;
  - privacy/CMP signals;
  - technical/publisher trust.
- Risk score, revenue exposure, tự mở lại và tự resolve finding qua verification scan.
- Evidence HTML/JSON private, SHA-256 integrity và download controller.
- Dashboard, Sites, Site Detail, Findings, Finding Evidence và Scan Center bằng Blade/Metronic.
- Trang đăng nhập/đăng xuất an toàn, giới hạn số lần thử và lệnh tạo admin; có thể tắt nếu dự án đã dùng Breeze/Jetstream/Fortify.
- Artisan command scan định kỳ, demo seeder và PHPUnit tests.

## Yêu cầu

- Laravel `10.x`.
- PHP `8.1+` với `curl`, `dom`, `json`, `libxml`, `mbstring`, `simplexml`.
- MySQL 8+, PostgreSQL 14+ hoặc SQLite cho development.
- Redis khuyến nghị cho queue/cache production.
- Bảng `users` chuẩn của Laravel 10. MaxGuard đã cung cấp auth tối thiểu; có thể dùng auth hiện có của dự án thay thế.

Xem [`composer.maxguard.json`](composer.maxguard.json) để kiểm tra compatibility, nhưng không ghi đè `composer.json` hiện tại bằng file này.

## Tích hợp vào Laravel 10

Commit hoặc backup dự án trước, rồi merge các thư mục sau vào root Laravel:

```text
app/Console/Commands
app/Contracts
app/Data
app/Detectors
app/Http/Controllers
app/Http/Requests
app/Jobs
app/Models
app/Services
config
database/migrations
database/seeders
public
resources/views
routes/web.php
tests
```

Nếu các file controller/model/route đã tồn tại, merge nội dung thay vì ghi đè mù quáng.

Copy cấu hình từ `.env.maxguard.example` vào `.env`. Tối thiểu:

```dotenv
QUEUE_CONNECTION=database
MAXGUARD_ROUTE_MIDDLEWARE=auth
MAXGUARD_PROVIDE_AUTH_ROUTES=true
MAXGUARD_QUEUE=scans
MAXGUARD_EVIDENCE_DISK=local
MAXGUARD_REQUIRE_OWNERSHIP=true
MAXGUARD_MAX_PAGES=100
MAXGUARD_HOST_RPS=1.5
```

Chạy cài đặt:

```bash
php artisan optimize:clear
php artisan migrate
php artisan queue:table       # chỉ khi project chưa có migration jobs và dùng database queue
php artisan migrate
php artisan storage:link      # tùy chọn; evidence vẫn được download qua controller private
```

Tạo tài khoản quản trị trước, sau đó mới tạo dữ liệu minh họa để các website demo được gắn đúng chủ sở hữu:

```bash
php artisan maxguard:create-admin
php artisan db:seed --class=MaxGuardSeeder
```

Chạy web và worker:

```bash
php artisan serve
php artisan queue:work --queue=scans --tries=3 --timeout=1800
```

Truy cập `/dashboard`.

### Auth có sẵn và cách sửa `Route [login] not defined`

Gói mặc định đăng ký `GET /login`, `POST /login` và `POST /logout`, vì vậy middleware `auth` luôn có named route `login`. Lệnh sau tạo user trong bảng `users` chuẩn của Laravel:

```bash
php artisan maxguard:create-admin
```

Có thể truyền option trong môi trường tự động; nên bỏ `--password` để nhập bí mật qua prompt:

```bash
php artisan maxguard:create-admin --name="Site Admin" --email="admin@example.com"
```

Nếu dự án đã dùng Breeze, Jetstream, Fortify hoặc auth riêng, giữ các route auth của dự án và tắt route tối thiểu của MaxGuard:

```dotenv
MAXGUARD_PROVIDE_AUTH_ROUTES=false
MAXGUARD_ROUTE_MIDDLEWARE=auth
```

Sau khi thay `.env`, luôn chạy `php artisan optimize:clear`. Route auth có thể kiểm tra bằng `php artisan route:list --name=login`.

### Chạy local hoàn toàn không có authentication

Chỉ dùng cho máy development:

```dotenv
MAXGUARD_ROUTE_MIDDLEWARE=
```

Production phải dùng `auth` và nên thêm Laravel Policies/tenant scope phù hợp mô hình người dùng của dự án.

## Scheduler Laravel 10

Trong `app/Console/Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('maxguard:scan-due-sites')
        ->everyFifteenMinutes()
        ->onOneServer()
        ->withoutOverlapping();
}
```

Cron server:

```cron
* * * * * cd /var/www/maxguard && php artisan schedule:run >> /dev/null 2>&1
```

## Cấu trúc xử lý scan

```text
ScanController / Artisan Command
        ↓
ScanDispatcher → RunWebsiteScan (queue)
        ↓
WebsiteCrawler → SafeHttpClient → PageInspector
        ↓
DetectorRegistry → Finding + EvidenceStore
        ↓
RiskScoreCalculator → Website/Scan summary
```

Các scan không chạy trong HTTP request. `ScanController` chỉ tạo record và dispatch job.

## Evidence

Mặc định evidence nằm ở `storage/app/maxguard/evidence` và không public. Production nên chuyển sang S3-compatible private bucket:

```dotenv
FILESYSTEM_DISK=s3
MAXGUARD_EVIDENCE_DISK=s3
AWS_BUCKET=maxguard-evidence
```

Mỗi evidence item có SHA-256, kích thước, MIME type, thời điểm capture và metadata. Không render trực tiếp HTML đã crawl trong Blade.

## Giới hạn cần hiểu đúng

Backend này là một production-oriented MVP hoàn chỉnh, nhưng detector HTML không thể tự chứng minh pháp lý rằng nội dung “vi phạm bản quyền” hoặc traffic “invalid”. Những kết luận đó cần thêm:

- search/index provider để tìm nguồn xuất bản sớm hơn;
- image reverse-search/perceptual hash service;
- Playwright renderer cho DOM sau JavaScript, mobile ad layout và CMP theo khu vực;
- Google Analytics/AdSense ingestion cho invalid-traffic analysis;
- human review và tài liệu quyền sử dụng nội dung.

UI vì vậy trình bày các kết quả chưa đủ bằng chứng dưới dạng **review signals**, không phải phán quyết pháp lý.

## Kiểm thử

```bash
php artisan test
```

Test đi kèm kiểm tra auth/login/logout, URL safety, HTML signal extraction, risk scoring và queue dispatch. Trước production cần bổ sung test với database/queue/storage thật và test tải trên crawler.

## Assets và giấy phép

Assets Metronic trong `public/vendor/metronic` được trích từ template người dùng cung cấp và đã tinh gọn còn production bundles/fonts. Chủ dự án phải duy trì giấy phép Metronic hợp lệ; không phân phối công khai gói này như một template độc lập.

Kiến trúc chi tiết và checklist deploy nằm trong [`docs/IMPLEMENTATION.md`](docs/IMPLEMENTATION.md). Nếu đang nâng cấp từ 1.0.0, làm theo [`docs/UPGRADE-1.0.1.md`](docs/UPGRADE-1.0.1.md).
