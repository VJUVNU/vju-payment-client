<?php

namespace Vju\PaymentClient\Exceptions;

use Vju\PaymentClient\Data\Order;

/**
 * 503 provider_unavailable: nhà cung cấp không phát được QR/tài khoản lúc này. Đơn VẪN được cổng
 * lưu — gọi lại sau (getOrder/order) để lấy thông tin thanh toán.
 */
class ProviderUnavailableException extends GatewayException
{
    /**
     * Đơn kèm trong phản hồi 503, nếu cổng có gửi (hợp đồng không bắt buộc).
     */
    public function order(): ?Order
    {
        $data = $this->body['data'] ?? $this->body['order'] ?? null;

        if (! is_array($data) || ! isset($data['ref'])) {
            return null;
        }

        return Order::fromArray($data);
    }
}
