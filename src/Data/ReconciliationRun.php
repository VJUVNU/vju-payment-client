<?php

namespace Vju\PaymentClient\Data;

use Carbon\CarbonImmutable;
use Vju\PaymentClient\Support\Cast;

final class ReconciliationRun
{
    public function __construct(
        public readonly ?string $provider,
        public readonly ?string $status,
        public readonly ?int $matched,
        public readonly ?int $mismatched,
        public readonly ?CarbonImmutable $ranAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            provider: Cast::str($data['provider'] ?? null),
            status: Cast::str($data['status'] ?? null),
            matched: Cast::int($data['matched'] ?? null),
            mismatched: Cast::int($data['mismatched'] ?? null),
            ranAt: Cast::date($data['ran_at'] ?? null),
        );
    }
}
