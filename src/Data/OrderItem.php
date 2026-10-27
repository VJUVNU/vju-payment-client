<?php

namespace Vju\PaymentClient\Data;

use Vju\PaymentClient\Support\Cast;

final class OrderItem
{
    public function __construct(
        public readonly string $label,
        public readonly int $amount,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            label: (string) Cast::str($data['label'] ?? ''),
            amount: (int) Cast::int($data['amount'] ?? 0),
        );
    }

    /**
     * @return array{label: string, amount: int}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'amount' => $this->amount];
    }
}
