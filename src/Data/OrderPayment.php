<?php

namespace Vju\PaymentClient\Data;

use Carbon\CarbonImmutable;
use Vju\PaymentClient\Support\Cast;

/**
 * Phần "payment" của Order: tài khoản/QR (type=qr) hoặc link thanh toán (type=redirect).
 */
final class OrderPayment
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?string $bankCode,
        public readonly ?string $accountNo,
        public readonly ?string $accountName,
        public readonly ?string $qrString,
        public readonly ?string $qrImage,
        public readonly ?string $checkoutUrl,
        public readonly ?CarbonImmutable $issuedAt,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            bankCode: Cast::str($data['bank_code'] ?? null),
            accountNo: Cast::str($data['account_no'] ?? null),
            accountName: Cast::str($data['account_name'] ?? null),
            qrString: Cast::str($data['qr_string'] ?? null),
            qrImage: Cast::str($data['qr_image'] ?? null),
            checkoutUrl: Cast::str($data['checkout_url'] ?? null),
            issuedAt: Cast::date($data['issued_at'] ?? null),
            raw: $data,
        );
    }
}
