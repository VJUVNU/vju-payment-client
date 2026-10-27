<?php

namespace Vju\PaymentClient\Data;

use Carbon\CarbonImmutable;
use Vju\PaymentClient\Exceptions\InvalidPayloadException;
use Vju\PaymentClient\Support\Cast;

/**
 * Đối tượng Order của hợp đồng §3. Các trường của "payment" (accountNo, qrImage, qrString,
 * checkoutUrl…) được kéo lên đây cho tiện; còn nguyên ở $payment. Payment null = cổng chưa phát
 * được QR/tài khoản (xem 503 provider_unavailable).
 */
final class Order
{
    public const TYPE_QR = 'qr';

    public const TYPE_REDIRECT = 'redirect';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public readonly ?string $bankCode;

    public readonly ?string $accountNo;

    public readonly ?string $accountName;

    public readonly ?string $qrString;

    public readonly ?string $qrImage;

    public readonly ?string $checkoutUrl;

    /**
     * @param  list<OrderItem>  $items
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $ref,
        public readonly ?string $payCode,
        public readonly ?string $method,
        public readonly ?string $type,
        public readonly ?string $status,
        public readonly int $amountDue,
        public readonly int $amountPaid,
        public readonly string $currency,
        public readonly ?string $customerName,
        public readonly ?string $description,
        public readonly array $items,
        public readonly ?int $priority,
        public readonly ?CarbonImmutable $validFrom,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly ?OrderPayment $payment,
        public readonly array $metadata,
        public readonly ?CarbonImmutable $createdAt,
        public readonly ?CarbonImmutable $updatedAt,
        public readonly array $raw = [],
    ) {
        $this->bankCode = $payment?->bankCode;
        $this->accountNo = $payment?->accountNo;
        $this->accountName = $payment?->accountName;
        $this->qrString = $payment?->qrString;
        $this->qrImage = $payment?->qrImage;
        $this->checkoutUrl = $payment?->checkoutUrl;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $ref = Cast::str($data['ref'] ?? null);

        if ($ref === null || $ref === '') {
            throw new InvalidPayloadException('Order thiếu trường "ref".');
        }

        $items = [];

        foreach (Cast::map($data['items'] ?? null) as $item) {
            if (is_array($item)) {
                $items[] = OrderItem::fromArray($item);
            }
        }

        return new self(
            ref: $ref,
            payCode: Cast::str($data['pay_code'] ?? null),
            method: Cast::str($data['method'] ?? null),
            type: Cast::str($data['type'] ?? null),
            status: Cast::str($data['status'] ?? null),
            amountDue: Cast::int($data['amount_due'] ?? null) ?? 0,
            amountPaid: Cast::int($data['amount_paid'] ?? null) ?? 0,
            currency: Cast::str($data['currency'] ?? null) ?? 'VND',
            customerName: Cast::str($data['customer_name'] ?? null),
            description: Cast::str($data['description'] ?? null),
            items: $items,
            priority: Cast::int($data['priority'] ?? null),
            validFrom: Cast::date($data['valid_from'] ?? null),
            expiresAt: Cast::date($data['expires_at'] ?? null),
            payment: is_array($data['payment'] ?? null) ? OrderPayment::fromArray($data['payment']) : null,
            metadata: Cast::map($data['metadata'] ?? null),
            createdAt: Cast::date($data['created_at'] ?? null),
            updatedAt: Cast::date($data['updated_at'] ?? null),
            raw: $data,
        );
    }

    public function isQr(): bool
    {
        return $this->type === self::TYPE_QR;
    }

    public function isRedirect(): bool
    {
        return $this->type === self::TYPE_REDIRECT;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function hasPaymentInfo(): bool
    {
        return $this->payment !== null;
    }

    public function remaining(): int
    {
        return max(0, $this->amountDue - $this->amountPaid);
    }
}
