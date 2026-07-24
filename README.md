# MaxGuard — Laravel 10 Complete Starter

Ứng dụng kiểm tra tuân thủ publisher/AdSense gồm backend Laravel 10 và giao diện Metronic Bootstrap 5. Gói này được thiết kế để **merge vào một dự án Laravel 10 hiện có**; không chứa thư mục `vendor/` hoặc framework Laravel.

## Chức năng đã có

- Database migrations cho websites, scans, pages, findings và evidence items.
- Eloquent models, relationships, scopes và route model binding.
- Tạo website có kiểm tra URL/DNS chống SSRF.
- Queue job scan với unique lock, retry, backoff, timeout và trạng thái tiến trình.
- Parallel scan pipeline: một orchestrator tạo các batch nhỏ, nhiều worker quét URL đồng thời và finalizer chốt duplicate/risk score.
- Queue doctor kiểm tra driver, jobs table/Redis và in chính xác lệnh worker cần chạy.
- Crawler full-site: đọc đệ quy sitemap index, WordPress/Yoast sitemap, sitemap khai báo trong robots.txt và internal links.
- Redirect được kiểm tra lại từng bước; chặn localhost, private/reserved IP, credential URL và port lạ.
- HTML inspector và bảy detector:
  - content quality;
  - internal duplicate content bằng 4-word Jaccard shingles;
  - media/copyright provenance signals;
  - ad density;
  - ads.txt presence and Google seller entry;
  - privacy/CMP signals;
  - technical/publisher trust.
- AI policy analyzer tùy chọn dùng OpenAI Responses API + Structured Outputs để đọc ngữ nghĩa, nhận diện nội dung cấm/nguy hiểm, deceptive claims, scaled low-value content và các rủi ro khó biểu diễn bằng regex.
- Incremental scan mặc định: URL không đổi được tái sử dụng kết quả tương thích, không chạy lại detector/AI và không tạo lại evidence.
- Latest-post sampling: nhập `Maximum newest posts` để lấy N bài mới nhất theo sitemap `<lastmod>` mà không báo `Partial` chỉ vì chủ động lấy mẫu.
- Giới hạn URL riêng cho từng scan, tiến độ scanned/discovered/cap, URL đang xử lý và số trang đã qua AI.
- Live findings report cập nhật 4 giây một lần trong khi worker vẫn chạy; phân biệt kết quả AI và rule engine.
- Xuất finding sang Excel `.xlsx` an toàn với bộ lọc hiện tại.
- Risk score, revenue exposure, tự mở lại và tự resolve finding qua follow-up scan.
- Evidence HTML/JSON private, SHA-256 integrity và download controller.
- Dashboard, Sites, Site Detail, Findings, Finding Evidence và Scan Center bằng Blade/Metronic.
- Trang đăng nhập/đăng xuất an toàn, giới hạn số lần thử và lệnh tạo admin; có thể tắt nếu dự án đã dùng Breeze/Jetstream/Fortify.
- Artisan command scan định kỳ, demo seeder và PHPUnit tests.

## Yêu cầu

- Laravel `10.x`.
- PHP `8.1+` với `curl`, `dom`, `fileinfo`, `gd`, `json`, `libxml`, `mbstring`, `simplexml`, `zip`.
- `phpoffice/phpspreadsheet:^1.29` cho báo cáo Excel.
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
DB_QUEUE_RETRY_AFTER=2400
MAXGUARD_ROUTE_MIDDLEWARE=auth
MAXGUARD_PROVIDE_AUTH_ROUTES=true
MAXGUARD_QUEUE=scans
MAXGUARD_PAGE_QUEUE=scan-pages
MAXGUARD_FINALIZE_QUEUE=scan-finalize
MAXGUARD_PAGE_BATCH_SIZE=10
MAXGUARD_PAGE_WORKERS=6
MAXGUARD_ORCHESTRATOR_TIMEOUT=900
MAXGUARD_PAGE_JOB_TIMEOUT=1800
MAXGUARD_FINALIZE_TIMEOUT=900
MAXGUARD_EVIDENCE_DISK=local
MAXGUARD_MAX_PAGES=0
MAXGUARD_MAX_DISCOVERED_URLS=100000
MAXGUARD_MAX_SITEMAPS=1000
MAXGUARD_FOLLOW_INTERNAL_LINKS=true
MAXGUARD_WORKER_MEMORY=1024
MAXGUARD_HOST_RPS=1.5
```

Cài dependency Excel vào project Laravel hiện có:

```bash
composer require phpoffice/phpspreadsheet:^1.29
```

Chạy cài đặt:

```bash
php artisan optimize:clear
php artisan migrate
php artisan maxguard:queue-doctor
php artisan storage:link      # tùy chọn; evidence vẫn được download qua controller private
```

Nếu queue doctor báo thiếu bảng `jobs` và project chưa có migration tạo bảng này:

```bash
php artisan queue:table
php artisan migrate
php artisan maxguard:queue-doctor
```

Tạo tài khoản quản trị trước, sau đó mới tạo dữ liệu minh họa để các website demo được gắn đúng chủ sở hữu:

```bash
php artisan maxguard:create-admin
php artisan db:seed --class=MaxGuardSeeder
```

Chạy web, một control worker và nhiều page worker:

```bash
php artisan serve
php artisan queue:work database --queue=scans,scan-finalize --sleep=2 --tries=3 --timeout=900 --memory=1024
php artisan queue:work database --queue=scan-pages --sleep=1 --tries=2 --timeout=1800 --memory=1024
```

Lệnh page worker cần chạy trong 6 process riêng (không phải gõ một lệnh có nghĩa là 6 worker). Production dùng file mẫu [`docs/supervisor-maxguard.conf.example`](docs/supervisor-maxguard.conf.example); `numprocs=6` tạo sáu process.

Truy cập `/dashboard`.

### Bấm Queue scan nhưng scan không chạy

Nút **Queue scan** chỉ ghi job vào queue; PHP web process không tự xử lý job nền. Với cấu hình đơn giản, dùng:

```dotenv
QUEUE_CONNECTION=database
MAXGUARD_QUEUE=scans
MAXGUARD_PAGE_QUEUE=scan-pages
MAXGUARD_FINALIZE_QUEUE=scan-finalize
```

Sau khi thay `.env`, chạy:

```bash
php artisan optimize:clear
php artisan migrate:status
php artisan migrate
php artisan maxguard:queue-doctor
php artisan queue:work database --queue=scans,scan-finalize --sleep=2 --tries=3 --timeout=900 --memory=1024
php artisan queue:work database --queue=scan-pages --sleep=1 --tries=2 --timeout=1800 --memory=1024
```

Nếu queue doctor báo thiếu bảng `jobs`, chỉ khi project chưa có migration tương ứng mới chạy `php artisan queue:table && php artisan migrate`.

Giữ các process `queue:work` chạy liên tục. Worker chỉ nghe `default` hoặc chỉ nghe `scans` sẽ không xử lý page jobs; phải có worker cho cả `scans`, `scan-pages` và `scan-finalize`. Chạy `php artisan maxguard:queue-doctor` để xem đúng lệnh theo `.env` hiện tại.

Sau mỗi lần đổi code hoặc `.env`:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Scan Center polling JSON mỗi 4 giây, cập nhật scanned/discovered URL, batch hoàn tất, URL active/waiting/failed, AI pages và findings ngay khi từng trang hoàn thành. Nếu dispatch thất bại, scan được đánh dấu `failed` thay vì làm website kẹt ở `scanning`.

Với mặc định `MAXGUARD_PAGE_BATCH_SIZE=10`, sample 300 bài tạo khoảng 30 page jobs. `MAXGUARD_PAGE_WORKERS=6` là số process vận hành khuyến nghị, nên tối đa sáu batch được xử lý đồng thời. Tăng worker chỉ giúp đến giới hạn CPU/RAM/API và tổng tốc độ host; mọi process cùng dùng global `MAXGUARD_HOST_RPS`. Nếu worker chạy trên nhiều máy, dùng Redis hoặc database làm shared cache để rate-limit lock được chia sẻ.

Nếu một scan cũ không được cập nhật trong ít nhất 30 phút và chặn scan mới:

```bash
php artisan maxguard:recover-stuck-scans --older-than=30
```

### Quét toàn bộ bài viết

`MAXGUARD_MAX_PAGES=0` nghĩa là không giới hạn số trang theo nghiệp vụ. Crawler vẫn áp dụng safety cap `MAXGUARD_MAX_DISCOVERED_URLS=100000`; tăng giá trị này nếu một site có nhiều hơn 100.000 URL.

Discovery thực hiện theo thứ tự:

1. Các dòng `Sitemap:` trong `robots.txt`.
2. `/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml`.
3. Đọc đệ quy mọi sitemap con trong `sitemapindex`.
4. Bổ sung URL còn thiếu từ internal links.

Mỗi scan lưu riêng `pages_discovered`, `pages_scanned`, sitemap errors, URL bị robots chặn, response lỗi và response không phải HTML. Nếu không quét đủ URL đã phát hiện hoặc chạm safety cap, trạng thái là `partial`, không phải `completed`.

Trên Dashboard, Site Detail và Scan Center có thể nhập `Maximum newest posts`. Khi sitemap có các file `post-sitemap*.xml`, crawler đọc toàn bộ sitemap index, gom mọi bài, sort theo `<lastmod>` giảm dần rồi mới chọn N URL. Ví dụ site có 11.425 bài và nhập `300`, scan sẽ chọn đúng 300 bài có lastmod mới nhất; category/page/author sitemap không chiếm quota này.

Nếu xử lý thành công đủ N bài đã chọn, scan có trạng thái `completed` và nhãn `Latest sample`, không phải `partial`. Metadata vẫn lưu tổng bài có thể chọn và tỷ lệ sample toàn site để báo cáo không gây hiểu nhầm. `Partial` chỉ còn dùng khi sitemap/discovery thực sự bị cắt bởi safety cap, sitemap lỗi, robots chặn hoặc có URL đã chọn không lấy được.

Nếu sitemap không phân tách post hoặc không có `<lastmod>`, MaxGuard dùng toàn bộ sitemap URLs và giữ thứ tự discovery làm fallback. Để trống trường giới hạn sẽ quét toàn bộ URL như trước.

Nếu site không có sitemap và homepage không để lộ internal links (ví dụ link được render hoàn toàn bằng JavaScript), discovery confidence là `low` và scan cũng được đánh dấu `partial` thay vì báo sai rằng một trang là toàn bộ website. Với website bạn được phép kiểm tra nhưng robots.txt chặn bài viết, có thể đặt `MAXGUARD_RESPECT_ROBOTS=false` rồi chạy lại.

`retry_after` của queue phải lớn hơn page-job timeout lớn nhất. Ví dụ trong `config/queue.php`:

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 2400),
```

Sau đó thêm `DB_QUEUE_RETRY_AFTER=2400` vào `.env`, chạy `php artisan optimize:clear` và kiểm tra bằng `php artisan maxguard:queue-doctor`.

### Không phân tích lại URL không đổi

Mặc định mọi scan mới là **incremental**. Crawler vẫn gửi HTTP request và trích text để tính `content_hash`; đây là bước cần thiết để biết bài viết đã được sửa hay chưa. Nếu hash không đổi và kết quả cũ có cùng ruleset/coverage, hệ thống:

- không chạy lại rule detectors;
- không gọi OpenAI;
- không tạo lại HTML snapshot/evidence;
- giữ nguyên finding đang mở của URL đó;
- tăng `pages_skipped_unchanged` và hiển thị **unchanged · analysis skipped** trong Recent scans.

URL tự động được phân tích lại khi nội dung đổi, ruleset đổi, scan hiện tại cần coverage rộng hơn, bật AI nhưng lần trước URL chưa được AI phân tích thành công, hoặc AI model đã đổi. Vì bản cũ chưa có analysis marker, lần scan đầu tiên sau khi nâng cấp 1.1.1 sẽ phân tích lại một lần; các lần sau mới tái sử dụng được.

Trong `/scan-center`, bật **Force re-analyze unchanged URLs** nếu cần audit lại toàn bộ. Scheduler/CLI tương đương:

```bash
php artisan maxguard:scan-due-sites --site=example.com --type=full --force
```

Không nên bỏ hẳn HTTP fetch chỉ dựa vào việc URL từng xuất hiện: cùng một URL có thể được thay nội dung và khi đó cache vĩnh viễn sẽ bỏ sót vi phạm mới.

### Bật AI policy analysis

Rule engine vẫn chạy trên mọi URL. AI chỉ chạy khi scan bật tùy chọn **AI policy analysis** và server có cấu hình:

```dotenv
OPENAI_API_KEY=your-project-api-key
MAXGUARD_AI_ENABLED=true
MAXGUARD_AI_MODEL=gpt-5.6-terra
MAXGUARD_AI_REASONING_EFFORT=low
MAXGUARD_AI_MAX_PAGES_PER_SCAN=100
MAXGUARD_AI_MIN_CONFIDENCE=70
```

Sau khi thay `.env`:

```bash
php artisan optimize:clear
php artisan queue:restart
```

AI nhận URL, metadata và tối đa `MAXGUARD_AI_MAX_INPUT_CHARS` ký tự nội dung text. Request dùng Responses API, JSON Schema strict, `store=false` và safety identifier đã hash. Nội dung trang được coi là dữ liệu không tin cậy để giảm prompt injection. Kết quả AI có rule key `ai.*`, hiển thị nhãn **AI**, lưu cùng HTML/JSON evidence và vẫn là tín hiệu cần review—not a guaranteed legal or AdSense enforcement decision.

`MAXGUARD_AI_MAX_PAGES_PER_SCAN=0` cho phép AI chạy trên mọi URL crawler lấy được. Production nên giữ cap và nhập `Maximum newest posts` trên dashboard để kiểm soát chi phí/thời gian. Nếu AI lỗi ở một URL, rule engine vẫn tiếp tục; số lỗi và token được ghi trong scan metadata. Scan rules-only không được tự resolve finding AI cũ, và AI finding chỉ được auto-resolve khi chính URL đó đã được AI phân tích lại thành công.

Scheduler cũng hỗ trợ giới hạn và AI:

```bash
php artisan maxguard:scan-due-sites --type=full --max-urls=500 --ai
```

### Live report và Excel

Trong `/scan-center`, bảng **Live findings report** hiển thị finding ngay sau khi từng URL được xử lý. Cột Analyzer phân biệt `AI` và `Rules`. Nút **Export Excel** tạo file `.xlsx` gồm scan ID, website, URL, nguồn phân tích, rule, severity, confidence, policy reference và remediation. Trang `/findings` cũng có nút Excel và giữ các filter hiện tại khi xuất.

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
DetectorRegistry + AiPolicyAnalyzer → Finding + EvidenceStore
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

Test đi kèm kiểm tra auth/login/logout, recursive sitemap parsing, full URL discovery, URL safety, HTML signal extraction, risk scoring và queue dispatch. Trước production cần bổ sung test tải với database/queue/storage thật.

## Assets và giấy phép

Assets Metronic trong `public/vendor/metronic` được trích từ template người dùng cung cấp và đã tinh gọn còn production bundles/fonts. Chủ dự án phải duy trì giấy phép Metronic hợp lệ; không phân phối công khai gói này như một template độc lập.

Kiến trúc chi tiết và checklist deploy nằm trong [`docs/IMPLEMENTATION.md`](docs/IMPLEMENTATION.md). Nếu đang nâng cấp bản hiện tại, làm theo [`docs/UPGRADE-1.2.0.md`](docs/UPGRADE-1.2.0.md).
