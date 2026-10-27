<?php

namespace Vju\PaymentClient\Data;

use DateTimeInterface;
use InvalidArgumentException;
use Vju\PaymentClient\Support\Cast;

/**
 * Thân của POST /orders (hợp đồng §3). Bắt buộc: ref, method, amount > 0, customer_name.
 *
 * Kiểm sơ bộ ở phía app (ref, số tiền, tổng items) để lỗi lập trình hiện ra ngay chỗ gọi thay vì
 * thành 422 từ cổng. Phần kiểm đầy đủ (tiền tố của app, phương thức đã bật…) vẫn là của cổng.
 */
final class OrderRequest
{
    public const REF_PATTERN = '/^[A-Za-z0-9]{1,14}$/';

    /**
     * @param  list<OrderItem>|null  $items
     * @param  array<string, string|int|float>|null  $metadata
     */
    public function __construct(
        public readonly string $ref,
        public readonly int $amount,
        public readonly string $method,
        public readonly string $customerName,
        public readonly ?string $description = null,
        public readonly ?array $items = null,
        public readonly ?string $payCode = null,
        public readonly ?int $priority = null,
        public readonly ?string $validFrom = null,
        public readonly ?string $expiresAt = null,
        public readonly ?string $customerEmail = null,
        public readonly ?array $metadata = null,
    ) {
        if (preg_match(self::REF_PATTERN, $ref) !== 1) {
            throw new InvalidArgumentException("ref \"{$ref}\" phải gồm 1–14 ký tự [A-Za-z0-9].");
        }

        // pay_code không kiểm dạng: hợp đồng chỉ định dạng cho ref; app legacy_unprefixed có mã riêng.
        if ($payCode !== null && trim($payCode) === '') {
            throw new InvalidArgumentException('pay_code không được rỗng.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('amount phải lớn hơn 0 (số nguyên VND).');
        }

        if ($method === '') {
            throw new InvalidArgumentException('Thiếu method (bidv, shb…). Truyền method hoặc đặt PAYMENT_GATEWAY_METHOD.');
        }

        if (trim($customerName) === '') {
            throw new InvalidArgumentException('Thiếu customer_name.');
        }

        if ($items !== null && $items !== []) {
            $sum = array_sum(array_map(fn (OrderItem $i) => $i->amount, $items));

            if ($sum !== $amount) {
                throw new InvalidArgumentException("Tổng items ({$sum}) phải bằng amount ({$amount}).");
            }
        }

        if ($metadata !== null && count($metadata) > 20) {
            throw new InvalidArgumentException('metadata tối đa 20 khoá.');
        }
    }

    /**
     * @param  iterable<OrderItem|array{label: string, amount: int}>|null  $items
     * @param  array<string, string|int|float>|null  $metadata
     */
    public static function make(
        string $ref,
        int $amount,
        string $method,
        string $customerName,
        ?string $description = null,
        ?iterable $items = null,
        ?string $payCode = null,
        ?int $priority = null,
        DateTimeInterface|string|null $validFrom = null,
        DateTimeInterface|string|null $expiresAt = null,
        ?string $customerEmail = null,
        ?array $metadata = null,
    ): self {
        return new self(
            ref: $ref,
            amount: $amount,
            method: $method,
            customerName: $customerName,
            description: $description,
            items: $items === null ? null : self::normalizeItems($items),
            payCode: $payCode,
            priority: $priority,
            validFrom: Cast::dateOnly($validFrom),
            expiresAt: Cast::dateOnly($expiresAt),
            customerEmail: $customerEmail,
            metadata: $metadata,
        );
    }

    /**
     * @param  iterable<OrderItem|array{label: string, amount: int}>  $items
     * @return list<OrderItem>
     */
    public static function normalizeItems(iterable $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $out[] = $item instanceof OrderItem ? $item : OrderItem::fromArray((array) $item);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'ref' => $this->ref,
            'method' => $this->method,
            'amount' => $this->amount,
            'customer_name' => $this->customerName,
            'description' => $this->description,
            'items' => $this->items === null ? null : array_map(fn (OrderItem $i) => $i->toArray(), $this->items),
            'pay_code' => $this->payCode,
            'priority' => $this->priority,
            'valid_from' => $this->validFrom,
            'expires_at' => $this->expiresAt,
            'customer_email' => $this->customerEmail,
            'metadata' => $this->metadata,
        ], fn ($v) => $v !== null);
    }
}
