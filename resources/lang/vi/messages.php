<?php

return [

    'nav' => [
        'overview' => 'Tổng quan',
        'issues' => 'Sự cố',
        'requests' => 'Requests',
        'jobs' => 'Jobs',
        'schedule' => 'Tác vụ định kỳ',
        'exceptions' => 'Ngoại lệ',
        'queries' => 'Truy vấn',
        'notifications' => 'Thông báo',
        'mail' => 'Email',
        'cache' => 'Cache',
        'outgoing' => 'Requests gửi đi',
        'users' => 'Người dùng',
        'logs' => 'Nhật ký',
        'settings' => 'Cài đặt',
        'support' => 'Hỗ trợ',
    ],

    'group' => [
        'activity' => 'Hoạt động',
        'monitoring' => 'Giám sát',
    ],

    'settings' => [
        'preferences' => 'Tuỳ chỉnh',
        'preferences_hint' => 'Các tuỳ chọn này được lưu trong trình duyệt và chỉ thay đổi giao diện bảng điều khiển cho riêng bạn.',
        'environment' => 'Môi trường',
        'environment_hint' => 'Giá trị chỉ đọc từ config/monitor.php. Thay đổi qua file cấu hình hoặc biến môi trường.',
        'recorders' => 'Bộ ghi nhận',

        'theme' => 'Giao diện',
        'theme_light' => 'Sáng',
        'theme_dark' => 'Tối',
        'theme_system' => 'Theo hệ thống',
        'language' => 'Ngôn ngữ',
        'timezone' => 'Múi giờ',
        'use_browser_timezone' => 'Dùng múi giờ trình duyệt',
        'save' => 'Lưu tuỳ chỉnh',
        'saved' => 'Đã lưu tuỳ chỉnh.',

        'environment_editable_hint' => 'Ghi đè giá trị mặc định trong config/monitor.php. Giá trị đã lưu sẽ được ưu tiên; mục nào chưa đổi vẫn theo file config.',
        'save_system' => 'Lưu cài đặt',
        'reset' => 'Khôi phục mặc định',
        'settings_saved' => 'Đã lưu cài đặt.',
        'settings_reset' => 'Đã khôi phục về mặc định config.',
        'periods_help' => 'Mỗi dòng gồm nhãn + số giờ. Vẫn có thể chọn khoảng bất kỳ từ lịch.',
        'periods_required' => 'Cần ít nhất một khoảng hợp lệ (có nhãn và số giờ).',
        'add_period' => 'Thêm khoảng',
        'period_label' => 'Nhãn',
        'period_hours' => 'Số giờ',
        'remove' => 'Xoá',
        'tz_search' => 'Tìm múi giờ…',
        'tz_no_match' => 'Không tìm thấy múi giờ.',
        'storage_note' => 'Nâng cao — đổi nơi lưu trữ hoặc đường dẫn dashboard sẽ áp dụng từ request kế tiếp; dashboard tải lại ở URL mới. Đổi nơi lưu trữ có thể khiến dữ liệu cũ tạm không hiển thị cho tới khi bảng mới có dữ liệu.',
        'path_help' => 'Tiền tố URL nơi dashboard được phục vụ.',
        'recorders_hint' => 'Bật/tắt các loại sự kiện được ghi nhận.',

        'recording' => 'Ghi nhận',
        'storage_driver' => 'Trình lưu trữ',
        'database_table' => 'Bảng cơ sở dữ liệu',
        'retention' => 'Thời gian lưu',
        'dashboard_path' => 'Đường dẫn bảng điều khiển',
        'dashboard_refresh' => 'Tần suất làm mới',
        'periods' => 'Khoảng thời gian',
        'request_threshold' => 'Ngưỡng request',
        'job_threshold' => 'Ngưỡng job',
        'query_threshold' => 'Ngưỡng query',
        'outgoing_request_threshold' => 'Ngưỡng outgoing request',

        'enabled' => 'Bật',
        'disabled' => 'Tắt',
        'hours' => ':count giờ',
    ],

];
