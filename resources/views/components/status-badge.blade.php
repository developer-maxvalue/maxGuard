@props(['status'])

@php
    $map = [
        'critical' => ['Nghiêm trọng', 'danger'],
        'high' => ['Cao', 'warning'],
        'review' => ['Cần xem xét', 'info'],
        'healthy' => ['Tốt', 'success'],
        'pending' => ['Đang chờ', 'secondary'],
        'scanning' => ['Đang quét', 'info'],
        'disabled' => ['Đã tắt', 'secondary'],
        'open' => ['Đang mở', 'danger'],
        'investigating' => ['Đang điều tra', 'warning'],
        'remediating' => ['Đang khắc phục', 'info'],
        'resolved' => ['Đã xử lý', 'success'],
        'queued' => ['Đang chờ', 'info'],
        'running' => ['Đang chạy', 'info'],
        'completed' => ['Hoàn tất', 'success'],
        'partial' => ['Một phần', 'warning'],
        'reused' => ['Đã tái sử dụng', 'success'],
        'success' => ['Thành công', 'success'],
        'skipped' => ['Đã bỏ qua', 'secondary'],
        'failed' => ['Thất bại', 'danger'],
        'cancelled' => ['Đã hủy', 'secondary'],
        'info' => ['Thông tin', 'info'],
    ];
    [$label, $tone] = $map[$status] ?? [ucfirst($status), 'secondary'];
@endphp

<span class="badge mg-status mg-status-{{ $tone }}">{{ $label }}</span>
