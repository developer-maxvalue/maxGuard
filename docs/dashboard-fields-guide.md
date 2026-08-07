# Chú thích các trường và chỉ số MaxGuard

Tài liệu này giải thích các trường, chỉ số và trạng thái đang xuất hiện trên các module nghiệp vụ của MaxGuard. Nội dung được đối chiếu trực tiếp từ Blade view, controller, model và service hiện tại.

## 1. Quy ước chung

### Finding là gì?

`Finding` là một vấn đề hoặc tín hiệu rủi ro được phát hiện trong quá trình quét. Một finding có thể gắn với một URL cụ thể hoặc áp dụng cho toàn website.

### Finding đang mở

Trong các phép đếm, “open finding” không chỉ là trạng thái `open` mà gồm:

- `open`: chưa bắt đầu xử lý;
- `investigating`: đang điều tra;
- `remediating`: đang khắc phục.

`resolved` không được tính là đang mở.

### Severity

| Giá trị | Ý nghĩa |
|---|---|
| `critical` | Rủi ro nghiêm trọng nhất, nên ưu tiên xử lý ngay. |
| `high` | Rủi ro cao, cần xử lý sớm. |
| `review` | Tín hiệu cần con người xem xét thêm. |
| `info` | Tín hiệu thông tin; có trong logic tính điểm nhưng hiện không có lựa chọn lọc riêng trên màn hình Findings. |

### Trạng thái sức khỏe website

Trạng thái được suy ra từ điểm:

| Khoảng điểm | Trạng thái |
|---|---|
| 0–69 | `critical` |
| 70–79 | `high` |
| 80–89 | `review` |
| 90–100 | `healthy` |

Ngoài ra website có thể có trạng thái vận hành như `pending`, `scanning` hoặc `disabled`.

### Analyzer

- `AI`: finding có `rule_key` bắt đầu bằng `ai.`.
- `Rules`: finding do detector/rule xác định trước tạo ra.

### Confidence

`Confidence` là độ tin cậy của bộ phân tích đối với finding, giới hạn từ 0 đến 100%. Đây không phải phần trăm website bị lỗi, xác suất mất doanh thu hay mức độ hoàn thành xử lý.

Confidence cũng ảnh hưởng đến điểm rủi ro: finding có confidence cao tạo mức trừ điểm lớn hơn.

---

## 2. Module Portfolio Dashboard

Màn hình: `/dashboard`

### Các thẻ tổng quan

| Chỉ số | Cách hiểu và cách tính |
|---|---|
| **Total sites** | Tổng số website người dùng hiện tại có quyền truy cập. |
| **Monitored** | Số website đã có `last_scanned_at`; website chưa từng scan không được tính. |
| **Compliance score** | Trung bình cộng `overall_score` của toàn bộ website, làm tròn thành số nguyên. Nếu chưa có website thì mặc định 100. Dòng “weighted health” trên giao diện hiện chỉ là mô tả; code thực tế chưa áp dụng trọng số. |
| **Critical issues** | Tổng finding có severity `critical` và trạng thái `open`, `investigating` hoặc `remediating`. |
| **High issues** | Tổng finding mức `high` đang ở trạng thái mở, điều tra hoặc khắc phục. |

### Compliance trend

- Lấy tối đa 16 scan gần nhất có trạng thái `completed` và có `score`.
- Dữ liệu được đảo về thứ tự cũ đến mới để vẽ xu hướng.
- Nếu chưa có dữ liệu, biểu đồ dùng giá trị mặc định 100.
- Nhãn giao diện ghi “last 12 weeks”, nhưng truy vấn hiện lấy **16 scan**, không nhóm dữ liệu theo tuần. Vì vậy đây là xu hướng các scan gần nhất, chưa phải chính xác 12 tuần.

### Portfolio health

| Trường | Cách tính |
|---|---|
| **Healthy** | Số website có trạng thái `healthy`. |
| **Need review** | Số website có trạng thái `review`, `high`, `pending` hoặc `scanning`. |
| **Critical** | Số website có trạng thái `critical`. |
| **Healthy percent** | `healthy / total × 100`. |
| **Review percent** | `(healthy + need review) / total × 100`; đây là điểm kết thúc cộng dồn để dựng thanh phân đoạn, không phải riêng tỷ lệ review. |

Website `disabled` vẫn nằm trong `total` nhưng không thuộc ba nhóm trên, vì vậy tổng ba nhóm có thể nhỏ hơn tổng website.

### Sites requiring attention

Hiển thị tối đa 8 website có `overall_score` thấp nhất.

| Cột | Ý nghĩa |
|---|---|
| **Site** | Domain website. |
| **Score** | Điểm sức khỏe tổng thể, từ 0 đến 100. |
| **Top risk** | Finding đang mở có severity cao nhất; thứ tự ưu tiên là critical → high → review. |
| **Findings** | Giá trị `open_findings_count` đã lưu trên website. |
| **Last scan** | Thời gian tương đối từ `last_scanned_at`; hiện `Never` nếu chưa scan. |
| **Status** | Trạng thái website theo điểm hoặc trạng thái vận hành hiện tại. |

### Quick scan

- **Maximum newest posts per site**: giới hạn số URL/bài mới nhất được chọn trên mỗi site.
- Để trống: dùng giới hạn/toàn bộ phạm vi do hệ thống cấu hình.
- Giá trị không được vượt `maxguard.crawler.max_discovered_urls`.

---

## 3. Module Sites

### 3.1. Danh sách Sites

Màn hình: `/sites`

Danh sách được sắp theo `overall_score` tăng dần, tức website rủi ro nhất xuất hiện trước, tối đa 25 website/trang.

| Cột | Ý nghĩa và cách tính |
|---|---|
| **Website** | Tên hiển thị và domain của website. |
| **Health** | `overall_score` và badge trạng thái. |
| **Pages** | Số URL đã scan trong lần scan gần nhất. Với dữ liệu cũ chưa có thống kê discovery, hệ thống dùng `pages_count`. |
| **Coverage** | `round(scanned / discovered × 100)`, tối đa 100%. Nếu chưa discover URL nào thì bằng 0%. |
| **Partial** | Xuất hiện khi `last_scan_partial = true` hoặc số URL scan nhỏ hơn số URL discover. |
| **Top issue** | Finding đang mở có mức nghiêm trọng và độ tin cậy cao nhất cần ưu tiên xử lý. |
| **Last scan** | Thời gian tương đối từ lần scan gần nhất hoặc `Never`. |

#### Bộ lọc

- Search tìm theo `name` hoặc `domain`.
- Status hỗ trợ: critical, high, review, healthy và pending.
- Backend còn chấp nhận `scanning` và `disabled`, dù giao diện hiện chưa có hai lựa chọn này.
- CSV export giữ nguyên bộ lọc đang áp dụng.

#### Thêm website

| Trường | Ý nghĩa |
|---|---|
| **Display name** | Tên thân thiện để nhận diện website. |
| **Start URL** | URL gốc dùng để bắt đầu discovery/crawl; hệ thống chuẩn hóa URL và trích domain. |

Website mới có trạng thái ban đầu là `pending`.

### 3.2. Chi tiết Site

Màn hình: `/sites/{site}`

#### Các chỉ số đầu trang

| Chỉ số | Ý nghĩa |
|---|---|
| **Overall score** | Điểm sức khỏe website từ 0–100, được tính từ các finding đang mở. Điểm cao là tốt. |
| **Scan coverage** | Tỷ lệ URL đã quét trên tổng URL phát hiện trong lượt quét gần nhất. |
| **Pages analyzed** | Số URL đã scan ở lần gần nhất. Ghi chú hiển thị coverage và tổng URL đã discover. |
| **Open findings** | Số finding đang ở open, investigating hoặc remediating. |

#### Công thức Overall score

Điểm bắt đầu từ 100. Mỗi finding tạo một mức phạt:

`penalty = severity weight × confidence factor × repeat factor`

Trọng số severity:

| Severity | Trọng số |
|---|---:|
| Critical | 32 |
| High | 18 |
| Review | 8 |
| Info | 2 |

- `confidence factor = max(0.35, confidence / 100)`.
- Finding đầu tiên trong mỗi category có `repeat factor = 1`.
- Finding lặp lại cùng category bị giảm ảnh hưởng theo `max(0.15, 0.42 / (index + 1))`.
- Tổng penalty bị chặn tối đa 95.
- `overall score = round(100 - penalty)`, giới hạn trong 0–100.

Như vậy nhiều finding trùng category vẫn làm giảm điểm, nhưng mức ảnh hưởng giảm dần để tránh một loại lỗi lặp lại áp đảo toàn bộ điểm.

#### Policy health breakdown

Các nhóm:

| Nhóm hiển thị | Category được gom |
|---|---|
| Prohibited & deceptive | Prohibited content, Deceptive practices |
| Copyright & duplicate | Copyright, Duplicate content |
| Content quality | Content quality, Technical trust |
| Ad experience | Ad experience |
| Privacy & consent | Privacy & consent |

Điểm policy dùng công thức đơn giản riêng:

- Critical: trừ 30/finding.
- High: trừ 18/finding.
- Review: trừ 8/finding.
- Khác: trừ 2/finding.
- `policy score = 100 - min(90, tổng penalty)`, thấp nhất 10 nếu chỉ xét công thức này.

Lưu ý: policy score không sử dụng confidence và repeat factor, nên không hoàn toàn giống Overall score.

#### GA4 traffic · last 7 days

| Trường | Ý nghĩa |
|---|---|
| **GA4 property ID** | ID property Google Analytics 4 được gắn với website. |
| **Last sync** | Thời điểm đồng bộ traffic gần nhất. |
| **Views** | `ga4_views_7d` của từng page trong 7 ngày, chỉ hiển thị trang có views > 0. |

Tối đa 20 trang, sắp giảm dần theo views. Thứ tự này được dùng để ưu tiên các trang traffic cao khi scan.

#### Highest-risk URLs

Hiển thị tối đa 10 finding đang mở, ưu tiên critical → high → review.

| Cột | Ý nghĩa |
|---|---|
| **URL** | Path của page; finding toàn site hiển thị `/`. |
| **Primary issue** | Tiêu đề finding. |
| **Severity** | Mức nghiêm trọng. |
| **Evidence** | Số evidence item gắn với finding. |

#### AI site assessment

- Sau khi một lượt quét hoàn tất, hệ thống tự tổng hợp nhận định AI nếu AI đã được cấu hình.
- AI chỉ nhận dữ liệu đã quét: điểm tổng thể, độ phủ, thống kê severity/category, finding, confidence, URL, detector signals, evidence và hướng khắc phục.
- Kết quả gồm mức rủi ro, tóm tắt toàn site, vấn đề chi tiết, căn cứ, hành động ưu tiên và giới hạn của dữ liệu.
- Bản đánh giá được lưu theo lượt quét. Nút **AI đánh giá lại** trên trang website sẽ đọc lại dữ liệu của lượt quét hoàn tất gần nhất và thay thế bản nhận định của lượt quét đó.
- Đây là nhận định hỗ trợ rà soát, không phải kết luận chắc chắn về quyết định thực thi của Google.

---

## 4. Module Findings & Cases

### 4.1. Danh sách Findings

Màn hình: `/findings`

#### Các thẻ tổng quan

| Chỉ số | Cách tính |
|---|---|
| **Critical** | Finding severity critical đang open, investigating hoặc remediating. |
| **High** | Finding severity high đang open, investigating hoặc remediating. |
| **In remediation** | Tất cả finding có status `remediating`, không phân biệt severity. |
| **Resolved this month** | Finding status `resolved` và `resolved_at` từ đầu tháng hiện tại. |

Các thẻ này là thống kê tổng thể trên dữ liệu người dùng được phép xem và **không thay đổi theo Search/Severity/Category của bảng**.

#### Bộ lọc

- Search tìm trong title, summary, public ID, website domain và page URL.
- Severity: critical, high hoặc review.
- Category: Copyright, Duplicate content, Ad experience, Content quality, Privacy & consent, Prohibited content, Deceptive practices và Technical trust.
- Backend còn hỗ trợ lọc `status`, `scan_id` và scan đang hoạt động qua query string, dù chưa có control tương ứng trên giao diện.
- Bảng sắp theo `last_seen_at` mới nhất và hiển thị 30 finding/trang.

#### Các cột

| Cột | Ý nghĩa |
|---|---|
| **Finding** | Tiêu đề vấn đề. Dòng phụ là public ID và phạm vi ảnh hưởng. |
| **Public ID** | Mã dạng `MG-yymmdd-xxxxx`, dùng làm định danh public và URL chi tiết. |
| **Affected** | `1 URL` nếu có `page_id`; nếu không là `Site-wide`. Hiện chưa đếm nhiều URL cho một dòng finding. |
| **Site** | Domain website. |
| **Category** | Nhóm chính sách/rủi ro. |
| **Analyzer** | AI hoặc Rules theo quy ước chung. |
| **Severity** | Critical, High hoặc Review. |
| **Confidence** | Độ tin cậy 0–100% của detector/analyzer. |
| **Detected** | Thời gian tương đối tính từ `last_seen_at`, tức lần gần nhất finding được nhìn thấy, không nhất thiết là lúc tạo ban đầu. |
| **Status** | Open, Investigating, Remediating hoặc Resolved. |

#### Export

- CSV giữ các bộ lọc hiện tại và xuất: ID, website, URL, category, severity, confidence, status, title và last seen.
- Excel giữ các bộ lọc và có thêm scan ID, source, rule key, summary, policy reference, revenue impact, first/last seen và remediation.

### 4.2. Chi tiết Finding

Màn hình: `/findings/{finding}`

| Trường/khu vực | Ý nghĩa |
|---|---|
| **Title** | Tên finding. |
| **Severity** | Mức nghiêm trọng. |
| **Site / URL** | Website và URL phát sinh evidence. Finding toàn site dùng start URL. |
| **Detected** | Lần gần nhất được thấy (`last_seen_at`). |
| **Confidence** | Độ tin cậy detector/analyzer. |
| **Investigate** | Chuyển status sang `investigating`. |
| **Start remediation** | Chuyển status sang `remediating`. |
| **Mark resolved** | Chuyển status sang `resolved` và ghi `resolved_at` bằng thời điểm hiện tại. |

#### Manual Google copyright check

Chỉ xuất hiện khi finding gắn với một page.

| Trường | Ý nghĩa |
|---|---|
| **Pending** | Chưa có kết luận thủ công. |
| **Clear** | Kiểm tra thủ công chưa thấy vi phạm. |
| **Suspected** | Có dấu hiệu nghi vấn nhưng chưa đủ xác nhận. |
| **Confirmed violation** | Người kiểm tra xác nhận vi phạm. |
| **Matching source URL** | URL nguồn/trang trùng khớp dùng làm căn cứ. |
| **Review notes** | Ghi chú kết luận của người kiểm tra. |

#### Dữ liệu làm căn cứ

- Hệ thống không lưu file HTML snapshot hoặc file signal JSON.
- Các câu trích dẫn, URL nguồn/đối chiếu, URL duplicate và chỉ số detector được lưu trong record finding/scan của database.
- Trang chi tiết chỉ hiển thị nội dung cần đối chiếu trực tiếp; không có khung browser mô phỏng, danh sách file evidence, kế hoạch khắc phục, bảng tín hiệu hoặc timeline evidence.

#### Risk assessment

- **Summary**: giải thích vì sao finding bị gắn cờ.
- **Policy mapping**: chính sách tham chiếu; nếu không có thì hiển thị “Manual policy mapping required”.
- **Detection signals**: key/value trong trường `signals`. Boolean hiển thị Yes/No; dữ liệu phức tạp hiển thị Recorded.

#### Remediation plan

Danh sách hành động từ mảng `remediation`. Checkbox hiện chỉ là hỗ trợ thao tác trên giao diện và không được lưu trạng thái từng bước xuống backend. Nút Mark resolved vẫn có thể được bấm độc lập với checkbox.

#### Evidence timeline

- Bắt đầu bằng thời điểm `first_seen_at` của finding.
- Sau đó hiển thị tối đa 3 evidence item mới nhất.
- Thời gian hiện chỉ hiển thị giờ/phút, không kèm ngày.

---

## 5. Module Scan Center

### 5.1. Khởi tạo scan

Màn hình: `/scan-center`

| Trường | Ý nghĩa |
|---|---|
| **Website** | Website cần scan. Backend cũng hỗ trợ giá trị `all-sites`. |
| **Scan type** | Phạm vi detector được chạy. |
| **Maximum newest posts** | Giới hạn số URL mới nhất được chọn. Để trống nghĩa là dùng phạm vi/global cap của hệ thống. |
| **AI policy analysis** | Bổ sung phân tích ngữ nghĩa bằng model AI đã cấu hình. Chỉ khả dụng khi AI enabled và có API key. |
| **Force re-analyze unchanged URLs** | Phân tích lại URL dù nội dung không đổi, thay vì tái sử dụng kết quả trước. |

Các scan type:

| Type | Phạm vi |
|---|---|
| `full` | Copyright, content quality, ads, technical trust và privacy. |
| `copyright` | Trùng nội dung, text similarity, hình ảnh và nguồn gốc media. |
| `ads` | Mật độ, vị trí quảng cáo và nguy cơ click nhầm. |
| `privacy` | CMP, consent mode và disclosure. |

Với kiểm tra quảng cáo, hệ thống có hai finding kết hợp nội dung và quảng cáo:

- `ads.on-page-without-content`: có tín hiệu/mã quảng cáo nhưng trang có dưới 80 từ có thể đọc;
- `ads.on-thin-content-page`: có tín hiệu/mã quảng cáo nhưng trang có dưới 300 từ có thể đọc.

Các ngưỡng này có thể cấu hình bằng `MAXGUARD_AD_PAGE_EMPTY_CONTENT_WORDS` và `MAXGUARD_AD_PAGE_THIN_CONTENT_WORDS`. Hệ thống nhận diện cả slot quảng cáo và mã Google Auto Ads có trong HTML nguồn.

### 5.2. Queue activity

| Chỉ số | Cách tính |
|---|---|
| **Scans running** | Số scan có status `running`. |
| **Scans queued** | Số scan có status `queued`. |
| **Targets running** | Số URL target đang được worker xử lý. |
| **Targets queued** | Số URL target đang chờ worker. |
| **Page workers** | Số worker xử lý page được khuyến nghị/cấu hình, tối thiểu 1. |
| **Batch size** | Số page mỗi batch, giới hạn 1–100. |
| **Utilization** | `running targets / (page workers × batch size) × 100`, làm tròn và chặn tối đa 100%. Đây là mức sử dụng capacity logic, không phải CPU/RAM thật. |

Queue driver, queue names và worker commands là thông tin vận hành để chạy queue worker. Khi dùng queue `sync`, tác vụ chạy trong request thay vì worker nền.

### 5.3. Recent scans

Hiển thị tối đa 10 scan mới nhất.

| Cột/chỉ số | Ý nghĩa |
|---|---|
| **Website** | Domain được scan. |
| **Type** | Loại scan. |
| **Status** | queued, running, completed, partial, failed hoặc cancelled. |
| **Progress** | Tiến độ phần trăm lưu trong scan. Discovery bắt đầu khoảng 5%; xử lý URL tăng dần đến tối đa 94%; finalize thành công đặt 100%. |
| **URLs checked / total** | `pages_scanned / pages_discovered` trong phạm vi đã chọn. |
| **Analyzed** | `pages_scanned - pages_skipped_unchanged`. |
| **Unchanged · analysis skipped** | URL có nội dung không đổi và được tái sử dụng, không chạy lại analysis. |
| **Latest sample** | Chỉ quét nhóm bài mới nhất theo `lastmod`, thay vì toàn bộ URL khả dụng. |
| **Available URLs** | Tổng URL có thể chọn trước khi áp dụng sample/cap. |
| **Sitemaps** | Số sitemap đã xử lý. |
| **Failed** | Số HTTP request thất bại trong discovery/scan metadata. |
| **Blocked** | Số URL bị robots.txt chặn. |
| **Batches completed / total** | Tiến độ batch khi chạy song song. |
| **URLs active / waiting** | Target đang chạy / đang chờ. |
| **URL jobs failed** | Target job thất bại. |
| **Now** | URL hệ thống ghi nhận đang xử lý. |
| **Limit** | `max_urls`; nếu không có thì hiển thị Global. |
| **AI pages** | Số page thực sự được AI phân tích. |
| **AI safety cap reached** | Đã đạt giới hạn page AI cho mỗi scan. |
| **AI errors** | Số lỗi phát sinh khi gọi/xử lý AI. |
| **Rules only** | Scan không bật AI. |
| **Forced re-analysis** | Scan bật `force_rescan`. |
| **Findings** | Tổng finding phát hiện trong scan. |
| **From AI** | Phần finding do AI tạo. |
| **Started** | Thời gian tương đối từ `started_at`, hoặc `created_at` nếu chưa bắt đầu. |

### 5.4. Live findings report

Hiển thị tối đa 100 finding có `last_seen_at` mới nhất và tự cập nhật từ endpoint live.

Các trường Site/URL, Issue, Analyzer, Severity, Confidence và Detected có cùng ý nghĩa với module Findings.

### 5.5. Chi tiết một scan

Màn hình: `/scan-center/{scan}`

#### Thẻ tổng quan

| Chỉ số | Ý nghĩa |
|---|---|
| **Progress** | Tiến độ toàn scan. |
| **Checked** | `pages_scanned / pages_discovered`. |
| **Running** | Target đang xử lý. |
| **Queued** | Target chờ worker. |
| **Reused** | Target tái sử dụng analysis cũ, không gọi lại API/detector tương ứng. |
| **Failed** | Target xử lý thất bại và cần xem debug. |

#### Bảng URL target

| Cột | Ý nghĩa |
|---|---|
| **#** | Thứ tự URL trong scan (`position + 1`). |
| **URL** | URL target; nếu đã có page còn hiển thị word count và HTTP status. |
| **Status** | queued, running, completed, reused hoặc failed. |
| **Current stage** | Bước pipeline hiện tại; `waiting` nếu chưa bắt đầu. |
| **Attempts** | Số lần worker đã thử xử lý target. |
| **Findings** | Số finding của target. |
| **Events** | Số telemetry event đã ghi trong timeline. |
| **Error** | Lỗi target gần nhất. |

Dữ liệu trạng thái target được polling mỗi 3 giây. Phân trang hiển thị 100 URL/trang.

### 5.6. URL processing detail

Màn hình: `/scan-center/{scan}/targets/{target}`

#### Processing timeline

| Trường | Ý nghĩa |
|---|---|
| **Stage** | Bước xử lý, ví dụ fetch, parse hoặc analyze tùy pipeline. |
| **Service** | Service thực hiện bước đó. |
| **Status** | Trạng thái event. |
| **Message** | Mô tả kết quả bước xử lý. |
| **Started at** | Thời điểm event bắt đầu. |
| **Duration ms** | Thời gian xử lý mili-giây; `running` nếu chưa kết thúc. |
| **HTTP status** | Mã phản hồi HTTP nếu event có request web. |
| **Request ID** | ID dùng truy vết request ngoài. |
| **Sanitized debug context** | Context debug đã được làm sạch trước khi hiển thị. |

#### Runtime

| Trường | Ý nghĩa |
|---|---|
| **Batch** | Số thứ tự batch chứa URL. |
| **Attempts** | Số lần xử lý. |
| **Stage** | Bước pipeline hiện tại. |
| **Reused** | Có tái sử dụng analysis cũ hay không. |
| **AI attempted** | Pipeline đã thử gọi AI hay chưa; không đồng nghĩa gọi thành công. |
| **AI tokens** | Input tokens / output tokens đã ghi nhận cho target. |

#### Findings on this scan

Liệt kê finding của page thuộc đúng scan hiện tại, gồm title, `rule_key` và confidence.

---

## 6. Trạng thái workflow

### Finding

`open → investigating → remediating → resolved`

Giao diện cho phép chuyển trực tiếp theo các nút hiện có; backend hiện không bắt buộc phải đi tuần tự qua từng bước.

### Scan

| Trạng thái | Ý nghĩa |
|---|---|
| `queued` | Đã tạo, chờ worker. |
| `running` | Đang discovery hoặc xử lý URL. |
| `completed` | Hoàn thành đầy đủ. |
| `partial` | Hoàn thành nhưng coverage không đầy đủ hoặc có phần bị giới hạn/lỗi. |
| `failed` | Scan thất bại. |
| `cancelled` | Scan bị hủy. |

### Scan target

| Trạng thái | Ý nghĩa |
|---|---|
| `queued` | URL chờ worker. |
| `running` | URL đang xử lý. |
| `completed` | URL đã xử lý xong. |
| `reused` | Dùng lại kết quả cũ do nội dung không đổi. |
| `failed` | URL xử lý thất bại. |

---

## 7. Các lưu ý về giao diện hiện tại

1. Các thẻ Critical/High trên Findings không phản ứng theo bộ lọc của bảng.
2. Compliance trend ghi “12 weeks” nhưng dữ liệu hiện là tối đa 16 scan hoàn tất gần nhất, chưa aggregate theo tuần.
3. Compliance score ghi “weighted health” nhưng hiện là trung bình cộng, chưa có trọng số.
4. Policy score và Overall score dùng hai công thức khác nhau.
5. Affected chỉ phân biệt `1 URL` và `Site-wide`, chưa tổng hợp một finding ảnh hưởng nhiều URL.
6. Checkbox trong Remediation plan chưa được lưu; chỉ status finding được cập nhật.
7. Badge component chưa có mapping màu riêng cho `remediating` và `reused`, nên các giá trị này dùng màu fallback.
8. Một số chuỗi trong Blade đang có dấu hiệu sai encoding như `Â·` hoặc chữ Việt bị mojibake; đây là lỗi hiển thị văn bản, không thay đổi ý nghĩa dữ liệu.

## 8. Module System Administration

Màn hình: `/admin`

Chỉ tài khoản có `is_admin = true` được truy cập. Admin nhìn thấy dữ liệu của tất cả owner trên cả trang quản trị và các màn Dashboard, Sites, Findings, Scan Center hiện hữu.

### Chỉ số tổng quan

| Chỉ số | Ý nghĩa |
|---|---|
| **Users** | Tổng tài khoản đăng ký. |
| **Sites** | Tổng website của mọi owner. |
| **Active scans** | Tổng scan đang queued hoặc running. |
| **Open findings** | Finding toàn hệ thống đang open, investigating hoặc remediating. |
| **Critical** | Finding critical đang mở trên toàn hệ thống. |
| **AI reviewed** | Số website đã có ít nhất một bản nhận định AI theo dữ liệu quét. |

Các bảng quản trị hiển thị 20 user mới nhất, 20 website điểm thấp nhất, 15 scan mới nhất và 15 finding đang mở có mức ưu tiên cao nhất.

### Quyền admin

- Truy cập dữ liệu Sites, Findings, Scans và Evidence của mọi owner.
- Mở trang chi tiết và thực hiện các thao tác quản lý hiện có.
- Xóa website của bất kỳ owner nào nếu website không có scan queued/running.
- Tài khoản thường chỉ thấy và quản lý website thuộc chính mình.

Lệnh `php artisan maxguard:create-admin` tạo tài khoản với `is_admin = true`. Khi migration quyền admin chạy trên một hệ thống cũ, tài khoản có ID nhỏ nhất được nâng thành admin để giữ quyền truy cập quản trị hiện hữu.

### Xóa website

Nút **Delete site** nằm trên trang chi tiết website.

- Chỉ owner hoặc admin được xóa.
- Không thể xóa khi website có scan `queued` hoặc `running`.
- Khi xác nhận, hệ thống xóa website và cascade toàn bộ scans, pages, findings, scan targets, telemetry, GA4 connection và copyright reviews liên quan.
- Evidence database records và các file evidence trong thư mục lưu trữ của website cũng bị xóa.
- Đây là thao tác không thể hoàn tác từ giao diện.

## 9. Nguồn code chính

- `resources/views/dashboard/index.blade.php`
- `resources/views/sites/index.blade.php`
- `resources/views/sites/show.blade.php`
- `resources/views/findings/index.blade.php`
- `resources/views/findings/show.blade.php`
- `resources/views/scans/index.blade.php`
- `resources/views/scans/show.blade.php`
- `resources/views/scans/target.blade.php`
- `app/Http/Controllers/DashboardController.php`
- `app/Http/Controllers/SiteController.php`
- `app/Http/Controllers/FindingController.php`
- `app/Http/Controllers/ScanController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Models/Website.php`
- `app/Models/Finding.php`
- `app/Models/Scan.php`
- `app/Models/ScanTarget.php`
- `app/Services/RiskScoreCalculator.php`
- `app/Services/ScanRunner.php`
