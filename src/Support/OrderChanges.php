<?php

namespace Vju\PaymentClient\Support;

use DateTimeInterface;
use InvalidArgumentException;
use Vju\PaymentClient\Data\OrderItem;
use Vju\PaymentClient\Data\OrderRequest;

/**
 * Dựng thân PATCH /orders/{ref} từ các tham số có tên — chỉ trường khác null.
 *
 * @internal
 */
final class OrderChanges
{
    /**
     * @param  iterable<OrderItem|array{label: string, amount: int}>|null  $items
     * @param  array<string, string|int|float>|null  $metadata
     * @return array<string, mixed>
     */
    public static function toArray(
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
    ): array {
        if ($amount !== null && $amount <= 0) {
            throw new InvalidArgumentException('amount phải lớn hơn 0 (số nguyên VND).');
        }

        if ($payCode !== null && preg_match(OrderRequest::REF_PATTERN, $payCode) !== 1) {
            throw new InvalidArgumentException("pay_code \"{$payCode}\" phải gồm 1–14 ký tự [A-Za-z0-9].");
        }

        $normalized = $items === null ? null : OrderRequest::normalizeItems($items);

        if ($normalized !== null && $normalized !== [] && $amount !== null) {
            $sum = array_sum(array_map(fn (OrderItem $i) => $i->amount, $normalized));

            if ($sum !== $amount) {
                throw new InvalidArgumentException("Tổng items ({$sum}) phải bằng amount ({$amount}).");
            }
        }

        $changes = array_filter([
            'amount' => $amount,
            'method' => $method,
            'customer_name' => $customerName,
            'description' => $description,
            'items' => $normalized === null ? null : array_map(fn (OrderItem $i) => $i->toArray(), $normalized),
            'pay_code' => $payCode,
            'priority' => $priority,
            'valid_from' => Cast::dateOnly($validFrom),
            'expires_at' => Cast::dateOnly($expiresAt),
            'customer_email' => $customerEmail,
            'metadata' => $metadata,
        ], fn ($v) => $v !== null);

        if ($changes === []) {
            throw new InvalidArgumentException('updateOrder() cần ít nhất một trường để sửa.');
        }

        return $changes;
    }
}
