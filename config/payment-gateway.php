<?php

return [

    /*
    | Gốc của cổng, KHÔNG kèm /api/v1 (vd. https://pay.vju.ac.vn). Đường dẫn đem ký luôn là
    | /api/v1/... — phần đường dẫn con nằm trong URL này (nếu có) không vào chuỗi ký, vì app
    | Laravel phía cổng cũng không thấy nó trong $request->path().
    */
    'url' => env('PAYMENT_GATEWAY_URL'),

    // Mã app (merchant_apps.code) và api_secret do quản trị cổng cấp.
    'key' => env('PAYMENT_GATEWAY_KEY'),
    'secret' => env('PAYMENT_GATEWAY_SECRET'),

    // Phương thức mặc định khi gọi order() không truyền method.
    'default_method' => env('PAYMENT_GATEWAY_METHOD'),

    'timeout' => (int) env('PAYMENT_GATEWAY_TIMEOUT', 15),
    'connect_timeout' => (int) env('PAYMENT_GATEWAY_CONNECT_TIMEOUT', 5),

    // Chỉ GET được thử lại, và chỉ khi lỗi kết nối. Mỗi lần thử ký lại với nonce mới.
    'retries' => (int) env('PAYMENT_GATEWAY_RETRIES', 2),
    'retry_sleep_ms' => (int) env('PAYMENT_GATEWAY_RETRY_SLEEP_MS', 300),

    // Cửa sổ lệch giờ cho phép khi kiểm chữ ký webhook (giây). Hợp đồng: ±300.
    'signature_window' => (int) env('PAYMENT_GATEWAY_SIGNATURE_WINDOW', 300),

    'webhook' => [
        'enabled' => (bool) env('PAYMENT_GATEWAY_WEBHOOK_ENABLED', true),
        'path' => env('PAYMENT_GATEWAY_WEBHOOK_PATH', 'payment-gateway/webhook'),

        // X-Vju-Key mà cổng gửi kèm webhook. Hợp đồng chốt là "payment-gateway".
        'key' => 'payment-gateway',

        // merchant_apps.webhook_secret. Lúc xoay khoá: ghi nhiều khoá, ngăn bằng dấu phẩy.
        'secret' => env('PAYMENT_GATEWAY_WEBHOOK_SECRET'),

        // Middleware thêm cho route webhook (vd. giới hạn IP). Không gắn nhóm "web" — không có CSRF/phiên.
        'middleware' => [],
    ],

    'pull' => [
        'limit' => (int) env('PAYMENT_GATEWAY_PULL_LIMIT', 100),
        'max_pages' => (int) env('PAYMENT_GATEWAY_PULL_MAX_PAGES', 20),

        // true thì gói tự đăng ký lịch payment:pull; mặc định false — app tự khai trong routes/console.php.
        'schedule' => (bool) env('PAYMENT_GATEWAY_PULL_SCHEDULE', false),
        'cron' => env('PAYMENT_GATEWAY_PULL_CRON', '*/5 * * * *'),
    ],

    // Kết nối CSDL cho bảng chống trùng/con trỏ/nonce. Nên để null (kết nối mặc định) để listener
    // ghi dữ liệu cùng một transaction với dòng chống trùng.
    'connection' => env('PAYMENT_GATEWAY_DB_CONNECTION'),

    // false nếu app đã publish migration và muốn tự quản lý.
    'run_migrations' => true,
];
