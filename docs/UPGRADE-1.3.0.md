# Nâng cấp MaxGuard 1.3.0

Phiên bản này bổ sung hai lớp kiểm tra tùy chọn: render quảng cáo bằng Chromium thật và tìm nội dung tương đồng trên website khác. Không có migration cơ sở dữ liệu mới.

## 1. Cài browser runtime

```bash
npm install
npx playwright install chromium
```

Bật kiểm tra và đặt giới hạn số trang cho mỗi scan:

```dotenv
MAXGUARD_BROWSER_AUDIT_ENABLED=true
MAXGUARD_NODE_BINARY=node
MAXGUARD_BROWSER_MAX_PAGES_PER_SCAN=50
MAXGUARD_BROWSER_TIMEOUT=45
MAXGUARD_BROWSER_SETTLE_MS=2500
MAXGUARD_BROWSER_AD_PROXIMITY_PX=24
```

Browser audit chạy hai viewport desktop/mobile. Script từ chối URL credential, port ngoài 80/443, localhost và IP private/reserved cho trang chính, redirect và subresource.

## 2. Bật kiểm tra sao chép ngoài website

Tạo Tavily API key tại `https://app.tavily.com`, sau đó cấu hình:

```dotenv
MAXGUARD_EXTERNAL_COPY_ENABLED=true
TAVILY_API_KEY=tvly-your-key
TAVILY_SEARCH_ENDPOINT=https://api.tavily.com/search
MAXGUARD_EXTERNAL_COPY_MAX_PAGES_PER_SCAN=30
MAXGUARD_EXTERNAL_COPY_QUERIES_PER_PAGE=2
MAXGUARD_EXTERNAL_COPY_CANDIDATES_PER_QUERY=5
MAXGUARD_EXTERNAL_COPY_MIN_WORDS=250
MAXGUARD_EXTERNAL_COPY_REVIEW_THRESHOLD=0.35
MAXGUARD_EXTERNAL_COPY_HIGH_THRESHOLD=0.65
```

Tavily chỉ dùng để tìm candidate với `search_depth=basic`, không yêu cầu answer hoặc raw content. MaxGuard tự tải candidate qua `SafeHttpClient`, trích văn bản và tính tỷ lệ 5-word shingle của trang nguồn xuất hiện trong candidate. Finding chứa `matched_url`, tỷ lệ và tối đa ba cụm trùng khớp. Kết quả là tín hiệu cần human review, không tự kết luận ai là chủ sở hữu hoặc trang nào xuất bản trước.

## 3. Khởi động lại tiến trình

```bash
php artisan optimize:clear
php artisan queue:restart
```

Scan mới dùng ruleset `1.3.0`. URL không đổi nhưng chưa có marker của hai lớp kiểm tra mới sẽ được phân tích lại khi tính năng tương ứng được bật.

## 4. Kiểm tra vận hành

- Mở chi tiết một Scan Target và xác nhận có stage `browser_audit` và `external_copy`.
- Nếu browser stage báo thiếu executable, chạy lại `npx playwright install chromium` bằng đúng user đang chạy queue worker.
- Nếu external-copy stage trả HTTP 401/429, kiểm tra Tavily API key và quota credits.
- Giữ page cap ở mức thấp trước, sau đó tăng theo CPU/RAM và quota API thực tế.
