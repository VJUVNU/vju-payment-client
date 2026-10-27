<?php

namespace Vju\PaymentClient\Http;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Vju\PaymentClient\Support\NonceStore;
use Vju\SatelliteContracts\Signing\RequestSignature;

/**
 * Kiểm chữ ký webhook của cổng (hợp đồng §5, cùng công thức §1) theo đúng thứ tự phía cổng:
 * X-Vju-Key → cửa sổ thời gian → nonce → chữ ký → nonce chưa dùng.
 *
 * Đường dẫn đem kiểm: hợp đồng nói là "path của webhook_url". App đặt trong thư mục con (vd.
 * https://host/nhaphoc/payment-gateway/webhook) thì $request->path() không có "/nhaphoc", nên thử cả
 * đường dẫn đầy đủ lẫn đường dẫn tương đối với app — khớp một trong hai là được.
 */
class VerifyWebhookSignature
{
    public function __construct(protected NonceStore $nonces) {}

    public function handle(Request $request, Closure $next): Response
    {
        $config = (array) config('payment-gateway', []);
        $expectedKey = (string) ($config['webhook']['key'] ?? 'payment-gateway');
        $secrets = self::secrets($config['webhook']['secret'] ?? null);
        $window = (int) ($config['signature_window'] ?? RequestSignature::DEFAULT_WINDOW);

        if ($secrets === []) {
            report(new \RuntimeException('PAYMENT_GATEWAY_WEBHOOK_SECRET chưa đặt — webhook của cổng thanh toán đang bị từ chối.'));

            return self::error('webhook_not_configured', 'Webhook chưa được cấu hình khoá.', 500);
        }

        $key = (string) $request->header(RequestSignature::HEADER_KEY, '');

        if (! hash_equals($expectedKey, $key)) {
            return self::error('unknown_sender', 'X-Vju-Key không phải của cổng thanh toán.', 401);
        }

        $timestamp = (int) $request->header(RequestSignature::HEADER_TIMESTAMP, '0');

        if (! RequestSignature::withinWindow($timestamp, time(), $window)) {
            return self::error('signature_expired', "Dấu thời gian nằm ngoài cửa sổ ±{$window} giây.", 401);
        }

        $nonce = (string) $request->header(RequestSignature::HEADER_NONCE, '');

        if ($nonce === '' || strlen($nonce) > RequestSignature::MAX_NONCE_LENGTH) {
            return self::error('nonce_missing', 'Thiếu X-Vju-Nonce hoặc chuỗi quá dài.', 401);
        }

        $signature = (string) $request->header(RequestSignature::HEADER_SIGNATURE, '');
        $body = (string) $request->getContent();
        $valid = false;

        foreach (self::candidatePaths($request) as $path) {
            if (RequestSignature::verify($secrets, $signature, $request->method(), $path, $timestamp, $nonce, $body)) {
                $valid = true;
                break;
            }
        }

        if (! $valid) {
            return self::error('signature_invalid', 'Chữ ký không khớp.', 401);
        }

        if (! $this->nonces->claim($key, $nonce, $window)) {
            return self::error('replay_detected', 'Nonce này đã được dùng.', 401);
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    public static function candidatePaths(Request $request): array
    {
        $query = $request->query();
        $paths = [
            RequestSignature::canonicalPath($request->getBaseUrl().$request->getPathInfo(), $query),
            RequestSignature::canonicalPath($request->path(), $query),
        ];

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    public static function secrets(mixed $value): array
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), $list), fn ($s) => $s !== ''));
    }

    public static function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
