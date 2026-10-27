<?php

namespace Vju\PaymentClient\Data;

use Carbon\CarbonImmutable;
use Vju\PaymentClient\Exceptions\InvalidPayloadException;
use Vju\PaymentClient\Support\Cast;

/**
 * Đối tượng Payment của hợp đồng §4 — một lần tiền vào. $id là mã giao dịch của cổng, tăng dần,
 * là khoá chống trùng phía app.
 */
final class Payment
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $ref,
        public readonly ?string $payCode,
        public readonly ?string $method,
        public readonly int $amount,
        public readonly ?string $providerTransactionId,
        public readonly ?CarbonImmutable $paidAt,
        public readonly ?string $payerNote,
        public readonly array $metadata,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $id = Cast::int($data['id'] ?? null);
        $amount = Cast::int($data['amount'] ?? null);

        if ($id === null || $id <= 0) {
            throw new InvalidPayloadException('Payment thiếu hoặc sai trường "id".');
        }

        if ($amount === null) {
            throw new InvalidPayloadException('Payment thiếu trường "amount".');
        }

        return new self(
            id: $id,
            ref: Cast::str($data['ref'] ?? null),
            payCode: Cast::str($data['pay_code'] ?? null),
            method: Cast::str($data['method'] ?? null),
            amount: $amount,
            providerTransactionId: Cast::str($data['provider_transaction_id'] ?? null),
            paidAt: Cast::date($data['paid_at'] ?? null),
            payerNote: Cast::str($data['payer_note'] ?? null),
            metadata: Cast::map($data['metadata'] ?? null),
            raw: $data,
        );
    }
}
