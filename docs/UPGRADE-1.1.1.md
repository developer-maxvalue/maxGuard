# Upgrade MaxGuard 1.1.0 → 1.1.1

## 1. Merge source changes

Merge `app`, `database/migrations`, `public`, `resources/views`, `tests` và các tài liệu vào dự án Laravel 10 hiện tại.

## 2. Run the incremental-scan migration

```bash
php artisan optimize:clear
php artisan migrate
```

Migration `2026_07_22_000004_add_incremental_scan_fields.php` thêm `scans.force_rescan` và `scans.pages_skipped_unchanged`.

## 3. Restart workers

```bash
php artisan queue:restart
php artisan maxguard:queue-doctor
```

Nếu không có Supervisor/Horizon tự khởi động lại worker, chạy:

```bash
php artisan queue:work database --queue=scans --sleep=2 --tries=3 --timeout=21600 --memory=1024
```

Không có biến `.env` mới bắt buộc cho incremental scan.

## 4. Behavior after upgrade

Trang đã được scan bằng 1.1.0 chưa có `pages.meta.maxguard_analysis`, nên scan đầu tiên trên 1.1.1 sẽ phân tích lại để tạo marker. Từ scan thứ hai, trang có content hash và coverage không đổi sẽ bỏ qua detector, AI và evidence.

Scan Center hiển thị:

- **checked / discovered**: số URL crawler đã fetch so với số URL phát hiện;
- **analyzed**: số URL thực sự chạy detector/AI;
- **unchanged · analysis skipped**: số URL tái sử dụng kết quả cũ.

Bật **Force re-analyze unchanged URLs** hoặc dùng `--force` nếu cần chạy lại mọi phân tích.

## 5. Verify

1. Queue một full scan nhỏ và chờ hoàn tất.
2. Queue lại cùng website, cùng scan type và không bật Force.
3. Xác nhận `analyzed` giảm và `unchanged · analysis skipped` tăng.
4. Sửa nội dung một bài, queue lại và xác nhận riêng URL đó được phân tích lại.
5. Bật Force và xác nhận mọi URL đều được phân tích lại.
