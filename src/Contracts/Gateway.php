<?php

namespace Vju\PaymentClient\Contracts;

use DateTimeInterface;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Data\OrderItem;
use Vju\PaymentClient\Data\OrderRequest;
use Vju\PaymentClient\Data\PaymentPage;
use Vju\PaymentClient\Data\Reconciliation;

/**
 * Hợp đồng chung của client thật (GatewayClient) và bản giả (FakeGateway). Type-hint interface
 * này (hoặc dùng facade PaymentGateway) để PaymentGateway::fake() thay được trong kiểm thử.
 */
interface Gateway
{
    /**
     * Tạo hoặc cập nhật đơn theo ref (POST /orders — upsert).
     *
     * @param  iterable<OrderItem|array{label: string, amount: int}>|null  $items
     * @param  array<string, string|int|float>|null  $metadata
     */
    public function order(
        string $ref,
        int $amount,
        ?string $method = null,
        ?string $customerName = null,
        ?string $description = null,
        ?iterable $items = null,
        ?string $payCode = null,
        ?int $priority = null,
        DateTimeInterface|string|null $validFrom = null,
        DateTimeInterface|string|null $expiresAt = null,
        ?string $customerEmail = null,
        ?array $metadata = null,
    ): Order;

    public function createOrder(OrderRequest $request): Order;

    public function getOrder(string $ref): Order;

    /**
     * PATCH /orders/{ref}: chỉ gửi các trường được truyền (khác null).
     *
     * @param  iterable<OrderItem|array{label: string, amount: int}>|null  $items
     * @param  array<string, string|int|float>|null  $metadata
     */
    public function updateOrder(
        string $ref,
        ?int $amount = null,
        ?string $method = null,
        ?string $customerName = null,
        ?string $description = null,
        ?iterable $items = null,
        ?string $payCode = null,
        ?int $priority = null,
        DateTimeInterface|string|null $validFrom = null,
        DateTimeInterface|string|null $expiresAt = null,
        ?string $customerEmail = null,
        ?array $metadata = null,
    ): Order;

    public function cancelOrder(string $ref): Order;

    public function payments(int $cursor = 0, ?int $limit = null): PaymentPage;

    public function reconciliation(DateTimeInterface|string $date): Reconciliation;
}
