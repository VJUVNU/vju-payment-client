<?php

namespace Vju\PaymentClient\Exceptions;

/**
 * 409: already_paid (sửa/huỷ đơn đã có tiền vào) hoặc order_closed (sửa đơn đã huỷ/hết hạn).
 */
class ConflictException extends GatewayException
{
    public function isAlreadyPaid(): bool
    {
        return $this->errorCode === 'already_paid';
    }

    public function isOrderClosed(): bool
    {
        return $this->errorCode === 'order_closed';
    }
}
