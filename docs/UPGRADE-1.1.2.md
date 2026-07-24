# Upgrade MaxGuard 1.1.1 → 1.1.2

## Thay đổi hành vi Maximum URLs

`Maximum URLs` được đổi thành `Maximum newest posts`:

1. Đọc toàn bộ sitemap index và sitemap con.
2. Ưu tiên URL thuộc `post-sitemap*.xml`.
3. Sort theo `<lastmod>` mới nhất.
4. Chọn tối đa N bài và không mở rộng thêm internal links.

Quét đủ N bài sẽ có trạng thái `completed` cùng nhãn `Latest sample`. `Partial` chỉ xuất hiện nếu coverage của chính sample bị lỗi hoặc discovery bị cắt ngoài ý muốn.

## Triển khai

Bản này không có migration mới. Merge source rồi chạy:

```bash
php artisan optimize:clear
php artisan queue:restart
php artisan maxguard:queue-doctor
```

Nếu worker không được Supervisor/Horizon tự khởi động lại:

```bash
php artisan queue:work database --queue=scans --sleep=2 --tries=3 --timeout=21600 --memory=1024
```

## Kiểm tra

1. Queue scan với `Maximum newest posts = 300`.
2. Xác nhận Recent scans hiển thị `Latest sample`.
3. Xác nhận `300 / 300 checked / selected` nếu mọi URL thành công.
4. Xác nhận status là `Completed`, không phải `Partial`.
5. Kiểm tra metadata có `sampling_mode=latest_posts` và `available_urls` là tổng số bài trong post sitemap.
