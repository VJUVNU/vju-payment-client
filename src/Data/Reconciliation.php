<?php

namespace Vju\PaymentClient\Data;

use Vju\PaymentClient\Support\Cast;

/**
 * Kết quả GET /reconciliation?date=… (hợp đồng §6). Chỉ có dòng của app gọi.
 */
final class Reconciliation
{
    /**
     * @param  list<ReconciliationRun>  $runs
     * @param  list<Discrepancy>  $discrepancies
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $date,
        public readonly array $runs,
        public readonly array $discrepancies,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data  phần "data" của phản hồi
     */
    public static function fromArray(array $data): self
    {
        $runs = [];
        $discrepancies = [];

        foreach (Cast::map($data['runs'] ?? null) as $row) {
            if (is_array($row)) {
                $runs[] = ReconciliationRun::fromArray($row);
            }
        }

        foreach (Cast::map($data['discrepancies'] ?? null) as $row) {
            if (is_array($row)) {
                $discrepancies[] = Discrepancy::fromArray($row);
            }
        }

        return new self(Cast::str($data['date'] ?? null), $runs, $discrepancies, $data);
    }

    /**
     * @return list<Discrepancy>
     */
    public function unresolved(): array
    {
        return array_values(array_filter($this->discrepancies, fn (Discrepancy $d) => ! $d->resolved));
    }
}
