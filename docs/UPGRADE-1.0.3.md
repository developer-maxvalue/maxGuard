# Nâng cấp MaxGuard 1.0.3 — full-site crawling

Phiên bản này sửa lỗi chỉ quét homepage trên các site WordPress/Yoast dùng sitemap index. Crawler mới phân biệt sitemap index với URL bài viết, đọc đệ quy sitemap con và báo coverage thực tế.

## 1. Cập nhật code và database

Chép đè source 1.0.3 rồi chạy:

```bash
php artisan optimize:clear
php artisan migrate
```

Migration mới thêm `last_discovered_pages`, `last_scanned_pages` và `last_scan_partial` vào bảng `websites`.

## 2. Cấu hình full crawl

```dotenv
MAXGUARD_MAX_PAGES=0
MAXGUARD_MAX_DISCOVERED_URLS=100000
MAXGUARD_MAX_SITEMAPS=1000
MAXGUARD_FOLLOW_INTERNAL_LINKS=true
MAXGUARD_JOB_TIMEOUT=21600
MAXGUARD_WORKER_MEMORY=1024
MAXGUARD_HOST_RPS=1.5
```

`MAXGUARD_MAX_PAGES=0` nghĩa là quét mọi URL phát hiện. Safety cap mặc định vẫn là 100.000 URL để ngăn queue vô hạn do calendar/search parameters; có thể tăng nếu cần.

## 3. Sửa queue timeout

Trong connection `database` của `config/queue.php`, đổi:

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 21900),
```

Thêm vào `.env`:

```dotenv
DB_QUEUE_RETRY_AFTER=21900
```

Nếu dùng Redis, áp dụng nguyên tắc tương tự cho connection Redis. `retry_after` bắt buộc lớn hơn `MAXGUARD_JOB_TIMEOUT`.

Kiểm tra và chạy worker:

```bash
php artisan optimize:clear
php artisan maxguard:queue-doctor
php artisan queue:restart
php artisan queue:work database --queue=scans --sleep=2 --tries=3 --timeout=21600 --memory=1024
```

## 4. Chạy scan lại

Nếu scan cũ đang chặn scan mới:

```bash
php artisan maxguard:recover-stuck-scans --older-than=30
```

Sau đó queue một full scan. Trong Scan Center cần thấy:

- `pages_discovered` bằng tổng URL thu được từ toàn bộ sitemap con và internal links;
- `pages_scanned` tăng dần;
- `completed` chỉ khi coverage đầy đủ;
- `partial` nếu có URL lỗi, bị robots chặn, sitemap con lỗi hoặc chạm safety cap.
- `partial` nếu không tìm thấy sitemap và homepage không cung cấp internal links để chứng minh coverage.

## 5. Kiểm thử

```bash
php artisan test --filter=SitemapParserTest
php artisan test --filter=WebsiteCrawlerTest
php artisan test --filter=ScanDispatcherTest
```
