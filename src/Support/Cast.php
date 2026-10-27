<?php

namespace Vju\PaymentClient\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/**
 * Đọc giá trị từ JSON của cổng một cách khoan dung: trường thiếu hoặc sai kiểu ra null thay vì
 * ném lỗi, để một trường lạ không làm rơi cả webhook.
 *
 * @internal
 */
final class Cast
{
    /** Múi giờ của các trường chỉ có ngày (hợp đồng: "giờ Hà Nội"). */
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public static function str(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        return (string) $value;
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) round((float) $value);
        }

        return null;
    }

    public static function bool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, self::TIMEZONE);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Trường chỉ có ngày gửi lên cổng: DateTimeInterface đổi về YYYY-MM-DD theo giờ Hà Nội; chuỗi
     * thì gửi nguyên (bên gọi tự chịu định dạng).
     */
    public static function dateOnly(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        return CarbonImmutable::instance($value)->setTimezone(self::TIMEZONE)->format('Y-m-d');
    }
}
