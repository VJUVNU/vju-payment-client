<?php

namespace Vju\PaymentClient\Client;

use Vju\PaymentClient\Exceptions\AuthenticationException;
use Vju\PaymentClient\Exceptions\ConflictException;
use Vju\PaymentClient\Exceptions\GatewayException;
use Vju\PaymentClient\Exceptions\NotFoundException;
use Vju\PaymentClient\Exceptions\ProviderUnavailableException;
use Vju\PaymentClient\Exceptions\ValidationException;

/**
 * Đổi envelope lỗi `{"error":{"code","message","fields"}}` (hợp đồng §2) thành exception có kiểu.
 * Ưu tiên theo error.code; mã lạ thì theo HTTP status; còn lại là GatewayException.
 *
 * @internal
 */
final class ErrorMapper
{
    /** @var array<string, class-string<GatewayException>> */
    public const BY_CODE = [
        'unknown_merchant' => AuthenticationException::class,
        'signature_expired' => AuthenticationException::class,
        'nonce_missing' => AuthenticationException::class,
        'signature_invalid' => AuthenticationException::class,
        'replay_detected' => AuthenticationException::class,
        'merchant_disabled' => AuthenticationException::class,
        'ip_not_allowed' => AuthenticationException::class,
        'order_not_found' => NotFoundException::class,
        'already_paid' => ConflictException::class,
        'order_closed' => ConflictException::class,
        'validation_failed' => ValidationException::class,
        'method_not_enabled' => ValidationException::class,
        'ref_not_allowed' => ValidationException::class,
        'provider_unavailable' => ProviderUnavailableException::class,
    ];

    /** @var array<int, class-string<GatewayException>> */
    private const BY_STATUS = [
        401 => AuthenticationException::class,
        403 => AuthenticationException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => ValidationException::class,
    ];

    /**
     * @param  array<string, mixed>|null  $body
     */
    public static function map(int $status, ?array $body): GatewayException
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = isset($error['code']) && is_string($error['code']) ? $error['code'] : null;
        $message = isset($error['message']) && is_string($error['message']) && $error['message'] !== ''
            ? $error['message']
            : "Cổng thanh toán trả HTTP {$status}".($code ? " ({$code})" : '').'.';
        $fields = self::fields($error['fields'] ?? null);

        $class = ($code !== null ? (self::BY_CODE[$code] ?? null) : null)
            ?? self::BY_STATUS[$status]
            ?? GatewayException::class;

        return new $class($message, $status, $code, $fields, $body);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function fields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $out = [];

        foreach ($fields as $name => $messages) {
            $out[(string) $name] = array_values(array_map('strval', (array) $messages));
        }

        return $out;
    }
}
