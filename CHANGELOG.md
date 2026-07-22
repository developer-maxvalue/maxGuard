# Changelog

## 1.0.1-laravel10 — 2026-07-22

- Sửa lỗi `Route [login] not defined` bằng auth route/controller/request hoàn chỉnh.
- Thêm giao diện login Metronic, login throttling, session regeneration và logout CSRF-safe.
- Thêm lệnh `maxguard:create-admin` và kiểm thử hồi quy auth.
- Gắn demo websites cho user đầu tiên để owner scope không làm dashboard trống sau khi seed.
- Sửa redirect `/home` của middleware guest, kích hoạt tìm kiếm/export website và global search.
- Sửa tên biến cache tương thích Laravel 10 (`CACHE_DRIVER`) và bổ sung test owner isolation.
- Khai báo extension `simplexml` mà sitemap crawler sử dụng.
- Cập nhật hướng dẫn tích hợp auth có sẵn, cache clearing và thứ tự cài đặt.

## 1.0.0-laravel10 — 2026-07-22

- Chuyển UI MaxGuard/Metronic thành backend Laravel 10 dùng Eloquent.
- Thêm migrations, models, requests, controllers, routes và owner scoping.
- Thêm website ownership verification.
- Thêm SSRF-safe HTTP client, crawler, robots/sitemap và response limits.
- Thêm detector registry, content/duplicate/copyright/ad/privacy/ads.txt/trust detectors.
- Thêm queued scan pipeline, risk scoring, immutable evidence và CSV export.
- Thêm scheduler command, demo seeder và tests.
