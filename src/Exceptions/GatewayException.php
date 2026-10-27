<?php

namespace Vju\PaymentClient\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Lỗi gốc của mọi lời gọi cổng. Bắt lớp này là bắt hết.
 *
 * $status là HTTP status (0 khi không tới được cổng), $errorCode là error.code của envelope lỗi,
 * $fields là error.fields (chỉ có ở 422), $body là toàn bộ phản hồi đã giải JSON.
 */
class GatewayException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly array $fields = [],
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function is(string $errorCode): bool
    {
        return $this->errorCode === $errorCode;
    }
}
