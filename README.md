# vju/payment-client

Gói Laravel để app VJU (Cổng sinh viên, OASS…) dùng **Cổng thanh toán VJU** (`vju-payment-gateway`) theo hợp đồng
[`payment-api-v1`](https://github.com/VJUVNU/vju-payment-gateway/blob/main/docs/api/payment-api-v1.md):
tạo đơn và lấy QR/tài khoản, nhận webhook đã ký, kéo `/payments` dự phòng, đối soát, và bản giả để kiểm thử.

> **Gói không chứa khoá ngân hàng nào.** Khoá BIDV/SHB… chỉ nằm ở Cổng thanh toán. App chỉ giữ hai khoá của
> chính nó với cổng (`PAYMENT_GATEWAY_SECRET`, `PAYMENT_GATEWAY_WEBHOOK_SECRET`), trong `.env`.

## Cài đặt

Gói và phụ thuộc `vju/satellite-contracts` là repo **private** — khai cả hai trong `composer.json` của app:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/VJUVNU/vju-payment-client" },
    { "type": "vcs", "url": "https://github.com/VJUVNU/satellite-contracts" }
]
```

```bash
composer require vju/payment-client:^0.1
```

Composer cần token GitHub đọc được hai repo đó:

- **Máy dev**: `composer config --global github-oauth.github.com <token>`, hoặc chạy một lần
  `export COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}"` trước lệnh composer.
  Đừng ghi token vào `composer.json` hay `auth.json` trong repo.
- **GitHub Actions** (OASS deploy bằng Actions): tạo secret `COMPOSER_AUTH` của repo app với giá trị
  `{"github-oauth":{"github.com":"<token>"}}`, rồi đặt cho job:

  ```yaml
  env:
    COMPOSER_AUTH: ${{ secrets.COMPOSER_AUTH }}
  ```

Service provider và facade `PaymentGateway` tự đăng ký qua package discovery.

### `.env`

```dotenv
PAYMENT_GATEWAY_URL=https://pay.vju.ac.vn      # gốc cổng, KHÔNG kèm /api/v1
PAYMENT_GATEWAY_KEY=enrollment                 # merchant_apps.code
PAYMENT_GATEWAY_SECRET=...                     # api_secret của app
PAYMENT_GATEWAY_WEBHOOK_SECRET=...             # merchant_apps.webhook_secret (xoay khoá: "moi,cu")
PAYMENT_GATEWAY_METHOD=bidv                    # tuỳ chọn: phương thức mặc định của order()

# Tuỳ chọn
PAYMENT_GATEWAY_TIMEOUT=15
PAYMENT_GATEWAY_RETRIES=2                      # chỉ GET, chỉ khi lỗi kết nối
PAYMENT_GATEWAY_WEBHOOK_PATH=payment-gateway/webhook
PAYMENT_GATEWAY_WEBHOOK_ENABLED=true
PAYMENT_GATEWAY_PULL_SCHEDULE=false            # true: gói tự đặt lịch payment:pull
```

### Cấu hình và migration

```bash
php artisan vendor:publish --tag=payment-gateway-config       # tuỳ chọn: config/payment-gateway.php
php artisan vendor:publish --tag=payment-gateway-migrations   # tuỳ chọn: chép migration vào app
php artisan migrate
```

Không publish migration thì gói tự nạp migration của nó khi `migrate`. Publish rồi thì tên tệp giữ nguyên nên
không chạy hai lần; muốn tắt hẳn việc tự nạp thì đặt `run_migrations => false` trong config.

Ba bảng: `payment_client_events` (chống trùng), `payment_client_cursors` (con trỏ của `payment:pull`),
`payment_client_nonces` (nonce webhook đã dùng).

### Đăng ký webhook với quản trị cổng

Gửi cho quản trị Cổng thanh toán:

- URL webhook: `https://<app>/payment-gateway/webhook` (hoặc đường dẫn đã đặt ở `PAYMENT_GATEWAY_WEBHOOK_PATH`)
  → ghi vào `merchant_apps.webhook_url`.
- Nhận lại `webhook_secret` → `PAYMENT_GATEWAY_WEBHOOK_SECRET`.

Route **không** nằm trong nhóm `web` (không CSRF, không phiên). Muốn thêm middleware (vd. giới hạn IP) thì khai
`webhook.middleware` trong config.

## Dùng

```php
use Vju\PaymentClient\Facades\PaymentGateway;

$don = PaymentGateway::order(
    ref: 'HP'.$hoaDon->id,
    amount: $conLai,
    method: 'bidv',
    customerName: $ten,
    expiresAt: $hoaDon->due_date,
);

if ($don->type === 'qr') {
    // $don->accountNo, $don->qrImage (có thể null — vẽ từ $don->qrString), $don->accountName, $don->bankCode
} else {
    return redirect($don->checkoutUrl);
}
```

`order()` là upsert theo `ref`, gọi mỗi lần mở trang cũng được: đơn đã thu thì cổng trả nguyên đơn. Các tham
số có tên khác: `description`, `items` (`[['label' => …, 'amount' => …]]`, tổng phải bằng `amount`), `payCode`,
`priority`, `validFrom`, `expiresAt`, `customerEmail`, `metadata`. Ngày nhận `DateTimeInterface` (đổi về
`YYYY-MM-DD` theo giờ Hà Nội) hoặc chuỗi (gửi nguyên).

Các lời gọi khác:

```php
PaymentGateway::getOrder('HP1234');                                // Order
PaymentGateway::updateOrder('HP1234', amount: 900000, expiresAt: '2026-11-15'); // chỉ gửi trường được truyền
PaymentGateway::cancelOrder('HP1234');
PaymentGateway::payments(cursor: 5000, limit: 100);               // PaymentPage
PaymentGateway::reconciliation('2026-10-26');                     // Reconciliation
```

Cần dựng sẵn `OrderRequest` thì dùng `PaymentGateway::createOrder(OrderRequest::make(...))`. Muốn tiêm phụ
thuộc thì type-hint `Vju\PaymentClient\Contracts\Gateway` (không type-hint `GatewayClient`, nếu không
`fake()` không thay được).

### Lỗi

| Exception | Khi |
|---|---|
| `AuthenticationException` | 401 `unknown_merchant` · `signature_expired` · `nonce_missing` · `signature_invalid` · `replay_detected`; 403 `merchant_disabled` · `ip_not_allowed` |
| `NotFoundException` | 404 `order_not_found` |
| `ConflictException` | 409 `already_paid` (`isAlreadyPaid()`) · `order_closed` (`isOrderClosed()`) |
| `ValidationException` | 422 `validation_failed` (`$e->fields`, `messagesFor('amount')`) · `method_not_enabled` · `ref_not_allowed` |
| `ProviderUnavailableException` | 503 `provider_unavailable` — đơn **vẫn được lưu**, gọi lại sau |
| `InvalidPayloadException` | phản hồi không phải JSON / thiếu trường bắt buộc |
| `GatewayException` | gốc của tất cả; `connection_failed` (không tới được cổng), `not_configured` (thiếu `.env`) |

Mọi exception có `$e->status`, `$e->errorCode`, `$e->fields`, `$e->body`. Lỗi lập trình (ref sai dạng, tổng items
lệch…) ném `InvalidArgumentException` ngay ở chỗ gọi, trước khi gửi.

Chỉ **GET** được thử lại, và chỉ khi lỗi kết nối. POST/PATCH tới được cổng mà mất phản hồi thì thử lại có thể
làm hai lần — app tự gọi lại `order()` (upsert theo `ref`, an toàn).

## Nhận tiền: listener

```php
use Vju\PaymentClient\Events\PaymentReceived;

class GhiNhanHocPhi
{
    public function handle(PaymentReceived $e): void
    {
        // $e->ref, $e->amount, $e->transactionId (= id giao dịch của cổng), $e->providerTransactionId,
        // $e->payCode, $e->method, $e->paidAt, $e->metadata, $e->payment (DTO đầy đủ), $e->source
    }
}
```

`OrderExpired` (`$e->ref`, `$e->order`) tới khi đơn quá hạn chưa thu — chỉ qua webhook.

### Ngữ nghĩa chống trùng — đọc kỹ

- Mỗi `transactionId` bắn `PaymentReceived` **đúng một lần**, dù tới bằng webhook, `payment:pull` hay cả hai.
  Webhook và pull dùng chung bảng `payment_client_events` với chỉ mục duy nhất; lượt trùng bị bỏ qua im lặng.
- Dòng chống trùng và **listener đồng bộ chạy trong cùng một transaction**:
  - listener ném lỗi → transaction huỷ, dòng chống trùng mất, webhook trả **500** → cổng gửi lại (1′, 5′, 30′,
    2h, 6h, 24h tới 3 ngày), và lần `payment:pull` sau cũng lấy lại được;
  - listener chỉ ghi CSDL trên cùng kết nối thì việc ghi của nó và dòng chống trùng **cùng thành hoặc cùng mất**
    — không cần tự chống trùng thêm.
- Listener làm việc **ngoài CSDL** (gửi mail, gọi API, đẩy hoá đơn điện tử) rồi mới ném lỗi thì việc đó sẽ
  chạy lại ở lần giao sau → listener ấy **phải tự idempotent** (vd. kiểm `transactionId` đã xử lý chưa), hoặc
  dời việc ngoài CSDL ra sau cùng.
- Listener `ShouldQueue`: đặt `public $afterCommit = true;` để job chỉ được xếp khi transaction commit. Từ lúc
  job đã xếp, lỗi của job **không** làm cổng gửi lại — job tự thử lại theo hàng đợi.
- Webhook phải trả lời trong **10 giây** (quá thì cổng coi là hỏng và gửi lại). Listener đồng bộ chậm (gọi API,
  sinh PDF, đẩy hoá đơn điện tử) nên chuyển sang `ShouldQueue` + `$afterCommit = true`.
- Để `PAYMENT_GATEWAY_DB_CONNECTION` trống (kết nối mặc định) để listener ghi cùng transaction.

## Kéo dự phòng: `payment:pull`

Webhook rơi thì lần kéo sau vẫn lấy được. Đặt lịch 5 phút trong `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('payment:pull')->everyFiveMinutes()->withoutOverlapping();
```

(hoặc `PAYMENT_GATEWAY_PULL_SCHEDULE=true` để gói tự đặt). Cần `schedule:work`/cron chạy thật — không có thì không
có gì kéo cả.

- Đọc con trỏ trong `payment_client_cursors`, kéo `/payments?cursor=…`, theo `has_more` tối đa
  `pull.max_pages` trang/lần, bắn cho id chưa thấy, tiến con trỏ (chỉ tiến, không lùi).
- Listener ném lỗi ở một khoản → con trỏ dừng ngay trước khoản đó, lệnh thoát mã lỗi, lần sau làm lại từ đó.
- Cổng dựng lại CSDL làm id đánh lại từ đầu → con trỏ đứng trước, lệnh im lặng không thấy gì. Khi đó chạy
  `php artisan payment:pull --cursor=0` (đã xử lý rồi thì bảng chống trùng vẫn chặn).

## Kiểm thử trong app: `PaymentGateway::fake()`

```php
use Vju\PaymentClient\Facades\PaymentGateway;
use Vju\PaymentClient\Data\OrderRequest;

public function test_sinh_vien_nop_hoc_phi(): void   // cần RefreshDatabase: simulatePayment ghi bảng chống trùng
{
    $fake = PaymentGateway::fake();

    $this->get('/hoc-phi/thanh-toan/42')->assertOk();

    $fake->assertOrderCreated(fn (OrderRequest $r) => $r->ref === 'HP42' && $r->amount === 1200000);

    $fake->simulatePayment('HP42');            // bắn PaymentReceived qua đúng đường chống trùng của webhook
    $this->assertDatabaseHas('hoa_don', ['id' => 42, 'status' => 'paid']);
}
```

Bản giả làm theo luật hợp đồng: upsert theo `ref`, đơn đã thu thì `order()` trả nguyên, sửa/huỷ đơn đã thu →
`ConflictException(already_paid)`, sửa đơn đã huỷ/hết hạn → `order_closed`, đơn lạ → `NotFoundException`.
`method: 'vnpay'` ra `type=redirect` (đổi bằng `useRedirectFor(...)`), còn lại `type=qr`.

| Hàm | Làm gì |
|---|---|
| `assertOrderCreated(fn\|ref = null)` · `assertOrderNotCreated(...)` · `assertOrderCreatedTimes(n, ...)` | khẳng định lời gọi tạo đơn |
| `assertOrderUpdated(ref, fn = null)` · `assertOrderCancelled(ref)` · `assertNothingSent()` | |
| `simulatePayment(ref, amount = còn lại, overrides = [])` | có tiền vào, bắn `PaymentReceived`; `payment:pull` trên bản giả cũng thấy |
| `simulateExpired(ref)` | bắn `OrderExpired` |
| `failNext(new ProviderUnavailableException(...))` | lời gọi kế tiếp ném lỗi đó |
| `createdOrders()` · `orders()` | xem dữ liệu đã ghi |

## Tương thích

| | PHP | Đã chạy bộ kiểm |
|---|---|---|
| Laravel 13 | 8.3 – 8.4 | 13.38 · testbench 11 · PHPUnit 12 |
| Laravel 12 (Cổng sinh viên) | 8.2 – 8.4 | 12.70 · testbench 10 · PHPUnit 12 |
| Laravel 11 (OASS 11.45) | 8.2 – 8.4 | 11.45.3 và 11.57 · testbench 9 · PHPUnit 11 — chạy tay, không có trong CI |

Ghi chú Laravel 11: Composer chặn cài Laravel 11.x vì một cảnh báo bảo mật, nên CI chỉ chạy 12 và 13; bộ kiểm trên
11 được chạy trong bản sao tạm với `audit.block-insecure=false`. Laravel **11.45** còn vỡ với `symfony/console`
7.4 (lỗi của framework, không phải của gói — mọi lệnh artisan đều hỏng); app 11.45 cần giữ `symfony/console`
≤ 7.3 như lock hiện có.

## Những điểm hợp đồng chưa nói rõ, gói đã chọn

- **Đường dẫn ký khi gọi cổng** luôn là `/api/v1/...`; phần đường dẫn con trong `PAYMENT_GATEWAY_URL` (nếu cổng
  đặt sau proxy dạng `https://host/gateway`) không vào chuỗi ký — vì app Laravel phía cổng cũng không thấy nó.
- **Đường dẫn kiểm webhook**: chấp nhận cả đường dẫn đầy đủ (kể cả thư mục con của app) lẫn đường dẫn tương đối
  với app.
- **Phản hồi Order** nhận cả dạng trần lẫn bọc `{"data": …}`.
- `valid_from` / `expires_at` gửi dạng `YYYY-MM-DD` như ví dụ hợp đồng.
- `order.expired` chống trùng theo `id` của envelope (Order không có id).
- Sự kiện webhook lạ → trả 200 `ignored` (để cổng không gửi lại mãi); thân hỏng → 422; listener lỗi → 500.
