<?php

namespace Vju\PaymentClient\Facades;

use Illuminate\Support\Facades\Facade;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Support\Inbox;
use Vju\PaymentClient\Testing\FakeGateway;

/**
 * @method static \Vju\PaymentClient\Data\Order order(string $ref, int $amount, ?string $method = null, ?string $customerName = null, ?string $description = null, ?iterable $items = null, ?string $payCode = null, ?int $priority = null, \DateTimeInterface|string|null $validFrom = null, \DateTimeInterface|string|null $expiresAt = null, ?string $customerEmail = null, ?array $metadata = null)
 * @method static \Vju\PaymentClient\Data\Order createOrder(\Vju\PaymentClient\Data\OrderRequest $request)
 * @method static \Vju\PaymentClient\Data\Order getOrder(string $ref)
 * @method static \Vju\PaymentClient\Data\Order updateOrder(string $ref, ?int $amount = null, ?string $method = null, ?string $customerName = null, ?string $description = null, ?iterable $items = null, ?string $payCode = null, ?int $priority = null, \DateTimeInterface|string|null $validFrom = null, \DateTimeInterface|string|null $expiresAt = null, ?string $customerEmail = null, ?array $metadata = null)
 * @method static \Vju\PaymentClient\Data\Order cancelOrder(string $ref)
 * @method static \Vju\PaymentClient\Data\PaymentPage payments(int $cursor = 0, ?int $limit = null)
 * @method static \Vju\PaymentClient\Data\Reconciliation reconciliation(\DateTimeInterface|string $date)
 *
 * @see \Vju\PaymentClient\Client\GatewayClient
 * @see \Vju\PaymentClient\Testing\FakeGateway
 */
class PaymentGateway extends Facade
{
    /**
     * Thay cổng thật bằng bản giả trong bộ nhớ cho tới hết bài kiểm.
     */
    public static function fake(): FakeGateway
    {
        $fake = new FakeGateway(
            static::getFacadeApplication()->make(Inbox::class),
            config('payment-gateway.default_method'),
        );

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return Gateway::class;
    }
}
