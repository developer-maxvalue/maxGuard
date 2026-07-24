# MaxGuard 2 – cài đặt và vận hành

> Tài liệu kiến trúc, flowchart, sequence diagram, ER diagram và checklist bàn giao:
> [`docs/PROJECT_HANDOVER.md`](docs/PROJECT_HANDOVER.md)

## Luồng xử lý

1. Người dùng thêm domain. Crawler đọc sitemap, sitemap index và internal links để lập danh sách page/bài viết.
2. Mỗi URL được tải an toàn, tách text và chạy rule local trước: nội dung mỏng, từ/cụm từ cảnh báo, copyright signals, quảng cáo, privacy, technical trust.
3. Duplicate detector so sánh nội dung trong cùng website sau khi toàn bộ batch hoàn tất.
4. Nếu Sightengine được bật, text được gửi đến API `general,self-harm`; class vượt threshold trở thành finding theo đúng URL.
5. Nếu bật AI, nội dung được gửi Gemini và kết quả policy có cấu trúc được lưu chung vào findings.
6. Scan loại `priority` đồng bộ GA4 của 7 ngày gần nhất và sắp URL từ traffic cao xuống thấp.
7. Page có cùng `content_hash` và cùng phiên bản phân tích sẽ được reuse ở lần sau. Chọn **Force re-analyze** chỉ khi cần quét lại.
8. Finding hiển thị website, URL, lỗi, severity, confidence, nguồn và evidence. Copyright Google là bước thủ công, có trạng thái riêng.

## Yêu cầu

- PHP 8.1+, Composer, MySQL/MariaDB
- Laravel queue bằng database hoặc Redis
- HTTPS public callback khi triển khai Google OAuth production

## Cài đặt

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
php artisan maxguard:create-admin
```

Copy các biến MaxGuard từ `.env.maxguard.example` sang `.env`, sau đó:

```bash
php artisan config:clear
php artisan cache:clear
```

Không commit `.env`. Nếu API secret Sightengine từng được gửi qua chat/log, nên rotate secret tại Sightengine trước khi chạy production.

## Sightengine

```dotenv
SIGHTENGINE_ENABLED=true
SIGHTENGINE_API_USER=your_user
SIGHTENGINE_API_SECRET=your_secret
SIGHTENGINE_MODELS=general,self-harm
SIGHTENGINE_VIOLATION_THRESHOLD=0.55
```

Threshold là số 0–1. `0.55` ưu tiên bắt rộng để human review; tăng lên nếu có nhiều false positive. Secret chỉ đọc từ environment, không lưu database/source.

## Gemini

Tạo API key trong Google AI Studio rồi cấu hình:

```dotenv
MAXGUARD_AI_ENABLED=true
MAXGUARD_AI_PROVIDER=gemini
GEMINI_API_KEY=your_key
MAXGUARD_AI_MODEL=gemini-2.5-flash
MAXGUARD_AI_OUTPUT_LANGUAGE=Vietnamese
MAXGUARD_AI_MAX_PAGES_PER_SCAN=100
```

Giới hạn page giúp kiểm soát chi phí. AI chỉ hỗ trợ đánh giá rủi ro; kết quả pháp lý/copyright cuối cùng cần con người xác nhận.

## GA4 OAuth và Data API

Trong Google Cloud Console:

1. Tạo project, bật **Google Analytics Data API**.
2. Cấu hình OAuth consent screen.
3. Tạo OAuth Client loại Web application.
4. Thêm redirect URI chính xác, ví dụ `https://maxguard.example/integrations/ga4/callback`.
5. Thêm user vận hành vào test users nếu consent screen còn Testing.

`.env`:

```dotenv
GOOGLE_CLIENT_ID=...
GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://maxguard.example/integrations/ga4/callback
MAXGUARD_GA4_TRAFFIC_DAYS=7
MAXGUARD_GA4_MAX_ROWS=1000
```

Vào **Sites → website → Connect Google Analytics**, cấp quyền read-only, nhập numeric GA4 Property ID (Admin → Property Settings), rồi bấm **Sync traffic now**. Token được mã hóa bằng `APP_KEY` trong database. Không thay `APP_KEY` khi còn token đang dùng.

## Queue production

Chạy một control worker và nhiều page worker:

```bash
php artisan queue:work database --queue=scans,scan-finalize --sleep=2 --tries=3 --timeout=900
php artisan queue:work database --queue=scan-pages --sleep=1 --tries=2 --timeout=1800
```

Nên quản lý bằng Supervisor; mẫu nằm ở `docs/supervisor-maxguard.conf.example`. Scheduler:

```cron
* * * * * cd /path/to/maxguard && php artisan schedule:run >> /dev/null 2>&1
```

## Sử dụng

- **Full**: toàn site, local + Sightengine và Gemini nếu được chọn.
- **Priority**: lấy thứ tự GA4 7 ngày, traffic cao trước.
- **Copyright**: tập trung copyright/duplicate.
- **Ads / Privacy**: lọc detector theo nhóm.
- **Findings**: lọc website/severity/status, mở từng URL để xem evidence.
- Trong chi tiết finding, dùng **Search exact title** rồi ghi `Clear`, `Suspected` hoặc `Confirmed violation`.

### Theo dõi và debug từng URL

Trong **Scan center**, bấm vào domain hoặc **View URL details**:

- Màn scan detail liệt kê toàn bộ URL đã discovery, trạng thái, stage hiện tại, attempts, findings và lỗi.
- Bấm **Debug** trên một URL để xem timeline `crawl → local_rules → sightengine → gemini → finished`.
- Mỗi event ghi duration, HTTP status, request ID, model/token và response class đã làm sạch.
- API key, OAuth token, secret và toàn bộ article text không bao giờ được ghi vào telemetry.
- Trang scan detail tự refresh trạng thái URL mỗi 3 giây.

URL cũ được scan trước migration telemetry vẫn có trạng thái tổng quát nhưng không có event lịch sử. Hãy force scan lại nếu cần timeline chi tiết cho URL đó.

Sau khi sửa bài, nội dung đổi làm `content_hash` đổi và hệ thống tự phân tích lại. Nếu chỉ đổi rule/config mà content không đổi, bật **Force re-analyze unchanged URLs**.

## Kiểm tra và xử lý lỗi

```bash
php artisan maxguard:queue-doctor
php artisan maxguard:recover-stuck-scans
php artisan test
```

- OAuth callback lỗi: kiểm tra URI phải giống tuyệt đối giữa Google Console và `.env`.
- GA4 HTTP 403: user OAuth không có quyền property hoặc Data API chưa bật.
- Không thấy URL GA4: GA4 trả `pagePath`; URL phải tồn tại trong domain/sitemap đã crawl.
- Sightengine không tạo finding: kiểm tra enabled, credential, log Laravel và threshold.
- Bài bị bỏ qua: đây là incremental scan; dùng force rescan nếu thực sự cần gọi lại API.
