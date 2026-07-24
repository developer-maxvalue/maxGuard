# Nâng cấp MaxGuard 1.0.2 cho Laravel 10

Phiên bản 1.0.2 loại bỏ hoàn toàn yêu cầu upload `/.well-known/maxguard-verification.txt`. Cột cũ trong database có thể giữ nguyên; backend không còn đọc hoặc kiểm tra cột đó.

## Cập nhật `.env`

Xóa `MAXGUARD_REQUIRE_OWNERSHIP`. Với một máy chủ chưa có Redis, dùng:

```dotenv
QUEUE_CONNECTION=database
MAXGUARD_QUEUE=scans
CACHE_DRIVER=file
SESSION_DRIVER=file
```

## Làm mới cache và queue

```bash
php artisan optimize:clear
php artisan migrate:status
php artisan migrate
php artisan maxguard:queue-doctor
php artisan queue:restart
```

Nếu doctor báo thiếu bảng `jobs` và project chưa có migration tương ứng, chạy `php artisan queue:table && php artisan migrate`, sau đó chạy lại doctor.

Giữ worker sau chạy liên tục:

```bash
php artisan queue:work database --queue=scans --sleep=2 --tries=3 --timeout=21600 --memory=1024
```

Nếu dùng Redis, thay `database` bằng `redis`. Điểm quan trọng là worker phải có `--queue=scans`; worker chỉ nghe `default` sẽ để scan nằm mãi ở trạng thái `queued`.

## Xử lý scan cũ bị kẹt

Trước tiên chạy worker và chờ job cũ được xử lý. Nếu scan không được cập nhật trong ít nhất 30 phút, hủy an toàn trạng thái cũ:

```bash
php artisan maxguard:recover-stuck-scans --older-than=30
```

Job cũ đến muộn sẽ thấy trạng thái `cancelled` và thoát mà không crawl. Không xóa toàn bộ bảng `jobs` khi còn job của tính năng khác.

## Kiểm tra

```bash
php artisan maxguard:queue-doctor
php artisan test --filter=ScanDispatcherTest
php artisan test --filter=QueueDoctorTest
```
