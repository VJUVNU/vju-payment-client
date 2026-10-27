<?php

namespace Vju\PaymentClient\Events;

use Carbon\CarbonImmutable;
use Vju\PaymentClient\Data\Payment;

/**
 * Có tiền vào một đơn của app. Bắn đúng MỘT lần cho mỗi $transactionId (id giao dịch của cổng),
 * dù tới bằng webhook, bằng payment:pull hay cả hai.
 *
 * Listener đồng bộ chạy BÊN TRONG transaction ghi dòng chống trùng: listener ném lỗi thì dòng đó
 * bị huỷ, webhook trả 500 để cổng gửi lại và lần kéo sau cũng lấy lại được. Vì vậy listener phải
 * idempotent nếu nó làm việc ngoài CSDL (gửi mail, gọi API) trước khi ném lỗi.
 */
final class PaymentReceived
{
    public readonly ?string $ref;

    public readonly ?string $payCode;

    public readonly int $amount;

    /** id giao dịch của cổng (Payment.id) — khoá chống trùng. */
    public readonly int $transactionId;

    /** Mã giao dịch phía ngân hàng (vd. FT26300123456). */
    public readonly ?string $providerTransactionId;

    public readonly ?string $method;

    public readonly ?CarbonImmutable $paidAt;

    /** @var array<string, mixed> */
    public readonly array $metadata;

    /**
     * @param  string  $source  webhook | pull | fake
     */
    public function __construct(
        public readonly Payment $payment,
        public readonly string $source,
        public readonly ?string $eventId = null,
    ) {
        $this->ref = $payment->ref;
        $this->payCode = $payment->payCode;
        $this->amount = $payment->amount;
        $this->transactionId = $payment->id;
        $this->providerTransactionId = $payment->providerTransactionId;
        $this->method = $payment->method;
        $this->paidAt = $payment->paidAt;
        $this->metadata = $payment->metadata;
    }
}
