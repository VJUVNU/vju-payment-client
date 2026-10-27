# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/), đánh số theo [SemVer](https://semver.org/lang/vi/).

## [0.1.0] — 2026-10-27

Bản đầu tiên, theo hợp đồng `payment-api-v1` của Cổng thanh toán VJU.

### Thêm

- `PaymentGateway::order()` / `createOrder()` / `getOrder()` / `updateOrder()` / `cancelOrder()` /
  `payments()` / `reconciliation()`, ký mọi lời gọi bằng `Vju\SatelliteContracts\Signing\RequestSignature`.
- DTO chỉ đọc: `Order` (kéo sẵn `accountNo`, `qrImage`, `qrString`, `checkoutUrl` lên đầu), `OrderPayment`,
  `OrderItem`, `OrderRequest`, `Payment`, `PaymentPage`, `Reconciliation`, `ReconciliationRun`, `Discrepancy`.
- Exception có kiểu theo envelope lỗi: `AuthenticationException`, `ValidationException` (kèm `fields`),
  `NotFoundException`, `ConflictException` (`already_paid` / `order_closed`), `ProviderUnavailableException`,
  `InvalidPayloadException`, gốc chung `GatewayException`.
- Chỉ thử lại GET khi lỗi kết nối; mỗi lần thử ký lại với nonce mới.
- Route webhook `POST /payment-gateway/webhook` (đổi được), kiểm chữ ký `X-Vju-Key: payment-gateway`, cửa sổ
  ±300 giây, chống phát lại nonce bằng bảng `payment_client_nonces`, nhận nhiều khoá khi xoay khoá.
- Event `PaymentReceived`, `OrderExpired`; chống trùng bằng bảng `payment_client_events` (chỉ mục duy nhất),
  ghi dòng chống trùng và chạy listener trong cùng một transaction.
- Lệnh `payment:pull` kéo `/payments` theo con trỏ trong `payment_client_cursors`, dùng chung bảng chống trùng
  với webhook; tuỳ chọn tự đăng ký lịch 5 phút.
- `PaymentGateway::fake()` với `assertOrderCreated()`, `assertOrderNotCreated()`, `assertOrderCreatedTimes()`,
  `assertOrderUpdated()`, `assertOrderCancelled()`, `assertNothingSent()`, `simulatePayment()`,
  `simulateExpired()`, `failNext()`.
