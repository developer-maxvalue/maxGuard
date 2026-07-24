# Changelog

## 1.2.0-laravel10 — 2026-07-22

- Đổi pipeline từ một job xử lý cả scan thành orchestrator → nhiều page-batch jobs → finalizer.
- Thêm bảng `scan_targets` để claim URL nguyên tử, theo dõi queued/running/completed/reused/failed và tiếp tục an toàn sau retry.
- Mặc định chia 10 URL/job và khuyến nghị 6 worker song song trên queue `scan-pages`; 300 URL tạo khoảng 30 batch.
- Thêm global per-host rate limiter qua Laravel Cache để nhiều worker vẫn tuân thủ tổng `MAXGUARD_HOST_RPS`.
- Tách duplicate-content thành page sketch song song và bước đối chiếu toàn site ở finalizer.
- Ghi cờ AI trước request để job retry không gọi AI hai lần cho cùng URL; giới hạn AI được reserve bằng database lock.
- Recent scans/live polling hiển thị batch hoàn tất, URL active/waiting/failed; Queue Doctor in riêng control worker và page workers.
- Giảm phạm vi timeout từ toàn bộ website xuống từng batch nhỏ và thêm Supervisor configuration mẫu.

## 1.1.2-laravel10 — 2026-07-22

- Đổi per-scan URL limit thành newest-post sampling khi sitemap có `post-sitemap` và `<lastmod>`.
- Đọc toàn bộ sitemap index trước, ưu tiên post sitemap, sort URL theo lastmod giảm dần rồi mới lấy N bài.
- Scan đủ sample được ghi `completed` thay vì `partial`; lỗi sitemap, URL fail, robots hoặc safety truncation vẫn là `partial`.
- Lưu metadata `sampling_mode`, `available_urls`, `site_urls_discovered`, `sampled_urls` và site coverage.
- Recent scans/live polling hiển thị `Latest sample` và “Latest N of M posts selected by lastmod”.
- Fixed sitemap sample không mở rộng thêm internal links ngoài N URL đã chọn.

## 1.1.1-laravel10 — 2026-07-22

- Thêm incremental scan mặc định: vẫn fetch URL để phát hiện thay đổi, nhưng bỏ qua detector, AI, snapshot và evidence khi content hash cùng coverage phân tích không đổi.
- Lưu analysis marker theo page gồm ruleset, scan scope, AI model và thời điểm phân tích để quyết định cache an toàn.
- Tự động phân tích lại khi nội dung/ruleset/scope/AI model thay đổi hoặc lần trước AI không hoàn tất.
- Thêm tùy chọn Force re-analysis trên Scan Center và `--force` cho scheduler command.
- Recent scans/live polling hiển thị riêng URL checked, URL analyzed và số kết quả unchanged được tái sử dụng.
- Bổ sung migration, hướng dẫn nâng cấp và kiểm thử dispatcher/incremental reuse.

## 1.1.0-laravel10 — 2026-07-22

- Thêm OpenAI Responses API analyzer với Structured Outputs, `store=false`, prompt-injection boundary, confidence gate và evidence metadata.
- Thêm giới hạn URL riêng theo từng scan trên Dashboard, Site Detail và Scan Center.
- Recent scans hiển thị rõ scanned/discovered/cap, current URL, số finding và số trang/finding AI.
- Thêm endpoint polling và Live findings report cập nhật trong khi scan đang chạy.
- Thêm xuất Excel `.xlsx`, phân biệt AI/Rules và giữ bộ lọc finding.
- Bảo vệ lifecycle: rules-only scan không resolve nhầm finding AI; AI chỉ resolve finding cũ trên URL đã phân tích AI lại thành công.
- Bổ sung migration, cấu hình `.env`, scheduler options, tài liệu nâng cấp và test AI/per-scan URL cap.

## 1.0.3-laravel10 — 2026-07-22

- Sửa lỗi WordPress/Yoast sitemap index khiến crawler chỉ quét homepage.
- Đọc sitemap từ robots.txt, `/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml` và mọi sitemap con.
- Hỗ trợ `MAXGUARD_MAX_PAGES=0` để quét mọi URL trong safety cap 100.000.
- Lưu discovered/scanned coverage chính xác và đánh dấu scan `partial` khi chưa đủ coverage.
- Thêm coverage migration, cảnh báo UI và thống kê lỗi discovery/crawl trong scan metadata.
- Tăng timeout full-site scan, kiểm tra `retry_after` bằng queue doctor và cập nhật worker command.
- Tối ưu duplicate detector bằng bottom-k shingle sketch/candidate index cho portfolio nhiều trang.
- Bổ sung test sitemap index, urlset, robots sitemap, crawl plan và recursive WordPress crawl.

## 1.0.2-laravel10 — 2026-07-22

- Bỏ hoàn toàn website ownership verification khỏi UI, route và luồng dispatch scan.
- Đổi cấu hình khởi đầu sang database queue/file cache để không phụ thuộc Redis.
- Thêm `maxguard:queue-doctor` kiểm tra queue backend, jobs table và in lệnh worker.
- Sửa dispatch failure làm website kẹt ở trạng thái `scanning`; lỗi queue giờ hiển thị trên giao diện.
- Scan Center hiển thị worker command và tự refresh khi có job queued/running.
- Thêm `maxguard:recover-stuck-scans` để hủy an toàn scan quá hạn và cho phép queue lại.
- Cải thiện thông báo lỗi khi scan bị bỏ qua và bổ sung tài liệu xử lý queue.

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
