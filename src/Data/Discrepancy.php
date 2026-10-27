<?php

namespace Vju\PaymentClient\Data;

use Vju\PaymentClient\Support\Cast;

final class Discrepancy
{
    public const MISSING_IN_GATEWAY = 'missing_in_gateway';

    public const MISSING_AT_PROVIDER = 'missing_at_provider';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public function __construct(
        public readonly ?string $provider,
        public readonly ?string $kind,
        public readonly ?string $providerTransactionId,
        public readonly ?int $amount,
        public readonly ?string $payCode,
        public readonly bool $resolved,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: Cast::str($data['provider'] ?? null),
            kind: Cast::str($data['kind'] ?? null),
            providerTransactionId: Cast::str($data['provider_transaction_id'] ?? null),
            amount: Cast::int($data['amount'] ?? null),
            payCode: Cast::str($data['pay_code'] ?? null),
            resolved: Cast::bool($data['resolved'] ?? false),
        );
    }
}
