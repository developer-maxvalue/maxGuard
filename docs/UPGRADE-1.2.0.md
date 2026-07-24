# Upgrade MaxGuard 1.1.2 → 1.2.0

## Vì sao cần bản này

Trước đây một scan 300 URL nằm trong một `RunWebsiteScan` job. Thêm worker không làm scan đó nhanh hơn vì chỉ một worker có thể giữ job. Bản 1.2.0 đổi thành:

1. `scans`: discovery sitemap và tạo `scan_targets`.
2. `scan-pages`: nhiều job xử lý từng nhóm URL song song.
3. `scan-finalize`: đối chiếu duplicate toàn sample, resolve finding cũ và chốt score/status.

Mặc định 300 URL / 10 URL mỗi batch = khoảng 30 jobs; sáu page worker xử lý tối đa sáu batch đồng thời. Global host limiter vẫn giữ tổng tốc độ ở `MAXGUARD_HOST_RPS`, không nhân tốc độ lên sáu lần.

## 1. Merge code và chạy migration

```bash
php artisan optimize:clear
php artisan migrate
```

Migration mới tạo bảng `scan_targets`. Không chạy worker mới trước khi migration thành công.

## 2. Cập nhật `.env`

```dotenv
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=2400

MAXGUARD_QUEUE=scans
MAXGUARD_PAGE_QUEUE=scan-pages
MAXGUARD_FINALIZE_QUEUE=scan-finalize
MAXGUARD_PAGE_BATCH_SIZE=10
MAXGUARD_PAGE_WORKERS=6
MAXGUARD_ORCHESTRATOR_TIMEOUT=900
MAXGUARD_PAGE_JOB_TIMEOUT=1800
MAXGUARD_FINALIZE_TIMEOUT=900
MAXGUARD_HOST_RPS=1.5
```

Trong `config/queue.php`, database connection nên đọc:

```php
'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 2400),
```

`retry_after` phải lớn hơn `MAXGUARD_PAGE_JOB_TIMEOUT`; nếu không cùng một batch có thể bị reserve hai lần khi worker cũ vẫn chạy.

## 3. Khởi động worker

Một control worker:

```bash
php artisan queue:work database --queue=scans,scan-finalize --sleep=2 --tries=3 --timeout=900 --memory=1024
```

Sáu page worker dùng cùng lệnh sau trong sáu process:

```bash
php artisan queue:work database --queue=scan-pages --sleep=1 --tries=2 --timeout=1800 --memory=1024
```

Production: sửa `/var/www/maxguard` và user trong [`supervisor-maxguard.conf.example`](supervisor-maxguard.conf.example), copy vào Supervisor rồi chạy:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Sau deploy/reconfigure:

```bash
php artisan optimize:clear
php artisan queue:restart
php artisan maxguard:queue-doctor
```

## 4. Cache dùng cho global rate limit

Các worker trên cùng server có thể dùng file/database/Redis cache. Nếu chạy worker trên nhiều server, bắt buộc chọn shared cache như Redis hoặc database; `array` cache không chia sẻ lock và không phù hợp production.

## 5. Kiểm tra

1. Queue scan với `Maximum newest posts = 300`.
2. Recent scans phải hiện khoảng `0 / 30 batches`, sau đó URL active/waiting giảm dần.
3. Live findings xuất hiện ngay khi từng URL xong, không đợi finalizer.
4. `php artisan maxguard:queue-doctor` phải liệt kê đủ ba queue.
5. Khi hoàn tất, `pages_scanned + targets_failed = pages_discovered`; bất kỳ target fail nào làm scan `Partial` và được hiển thị riêng.

Job dùng claim token nguyên tử. Dispatch trùng không quét lại target đã claim; retry của đúng job có thể tiếp tục target dang dở. AI được đánh dấu trước request để timeout/retry không tạo thêm một API call cho cùng URL.
