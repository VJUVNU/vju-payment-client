<?php

namespace Vju\PaymentClient\Data;

use Vju\PaymentClient\Support\Cast;

/**
 * Một trang của GET /payments.
 */
final class PaymentPage
{
    /**
     * @param  list<Payment>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly int $nextCursor,
        public readonly bool $hasMore,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body, int $requestedCursor = 0): self
    {
        $payments = [];

        foreach (Cast::map($body['data'] ?? null) as $row) {
            if (is_array($row)) {
                $payments[] = Payment::fromArray($row);
            }
        }

        $next = Cast::int($body['next_cursor'] ?? null);

        if ($next === null) {
            $next = $payments === [] ? $requestedCursor : end($payments)->id;
        }

        return new self($payments, $next, Cast::bool($body['has_more'] ?? false));
    }
}
