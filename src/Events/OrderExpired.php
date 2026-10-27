<?php

namespace Vju\PaymentClient\Events;

use Vju\PaymentClient\Data\Order;

/**
 * Đơn quá expires_at mà chưa thu (webhook order.expired). Chỉ tới qua webhook — /payments không
 * mang sự kiện này. Chống trùng theo id sự kiện của envelope.
 */
final class OrderExpired
{
    public readonly string $ref;

    public function __construct(
        public readonly Order $order,
        public readonly string $source,
        public readonly ?string $eventId = null,
    ) {
        $this->ref = $order->ref;
    }
}
