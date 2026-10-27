<?php

namespace Vju\PaymentClient\Client;

use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Data\OrderRequest;
use Vju\PaymentClient\Data\PaymentPage;
use Vju\PaymentClient\Data\Reconciliation;
use Vju\PaymentClient\Exceptions\GatewayException;
use Vju\PaymentClient\Exceptions\InvalidPayloadException;
use Vju\PaymentClient\Support\Cast;
use Vju\PaymentClient\Support\OrderChanges;
use Vju\SatelliteContracts\Signing\RequestSignature;

/**
 * Client HTTP của Cổng thanh toán (hợp đồng payment-api-v1). Mọi lời gọi ký bằng RequestSignature
 * với đúng đường dẫn chuẩn hoá + thân thô được gửi đi.
 *
 * Chỉ GET được thử lại, và chỉ khi lỗi kết nối: POST/PATCH tới được cổng mà mất phản hồi thì thử
 * lại có thể làm việc hai lần; app tự gọi lại (POST /orders upsert theo ref nên an toàn để gọi lại).
 */
class GatewayClient implements Gateway
{
    public const API_PREFIX = '/api/v1';

    /**
     * @param  array<string, mixed>  $config  nội dung config/payment-gateway.php
     */
    public function __construct(
        protected array $config,
        protected ?HttpFactory $http = null,
    ) {}

    public function order(
        string $ref,
        int $amount,
        ?string $method = null,
        ?string $customerName = null,
        ?string $description = null,
        ?iterable $items = null,
        ?string $payCode = null,
        ?int $priority = null,
        DateTimeInterface|string|null $validFrom = null,
        DateTimeInterface|string|null $expiresAt = null,
        ?string $customerEmail = null,
        ?array $metadata = null,
    ): Order {
        return $this->createOrder(OrderRequest::make(
            ref: $ref,
            amount: $amount,
            method: $method ?? (string) ($this->config['default_method'] ?? ''),
            customerName: (string) $customerName,
            description: $description,
            items: $items,
            payCode: $payCode,
            priority: $priority,
            validFrom: $validFrom,
            expiresAt: $expiresAt,
            customerEmail: $customerEmail,
            metadata: $metadata,
        ));
    }

    public function createOrder(OrderRequest $request): Order
    {
        return $this->orderFrom($this->request('POST', '/orders', [], $request->toArray()));
    }

    public function getOrder(string $ref): Order
    {
        return $this->orderFrom($this->request('GET', '/orders/'.rawurlencode($ref)));
    }

    public function updateOrder(
        string $ref,
        ?int $amount = null,
        ?string $method = null,
        ?string $customerName = null,
        ?string $description = null,
        ?iterable $items = null,
        ?string $payCode = null,
        ?int $priority = null,
        DateTimeInterface|string|null $validFrom = null,
        DateTimeInterface|string|null $expiresAt = null,
        ?string $customerEmail = null,
        ?array $metadata = null,
    ): Order {
        $changes = OrderChanges::toArray(
            $amount, $method, $customerName, $description, $items, $payCode,
            $priority, $validFrom, $expiresAt, $customerEmail, $metadata,
        );

        return $this->orderFrom($this->request('PATCH', '/orders/'.rawurlencode($ref), [], $changes));
    }

    public function cancelOrder(string $ref): Order
    {
        return $this->orderFrom($this->request('PATCH', '/orders/'.rawurlencode($ref), [], ['status' => Order::STATUS_CANCELLED]));
    }

    public function payments(int $cursor = 0, ?int $limit = null): PaymentPage
    {
        $limit = max(1, min(500, $limit ?? (int) ($this->config['pull']['limit'] ?? 100)));
        $body = $this->request('GET', '/payments', ['cursor' => max(0, $cursor), 'limit' => $limit]);

        return PaymentPage::fromArray($body, $cursor);
    }

    public function reconciliation(DateTimeInterface|string $date): Reconciliation
    {
        $body = $this->request('GET', '/reconciliation', ['date' => Cast::dateOnly($date)]);

        return Reconciliation::fromArray(Cast::map($body['data'] ?? $body));
    }

    /**
     * GET /api/health — không ký.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $response = $this->pending()->get($this->origin().'/api/health');

        return (array) ($response->json() ?? []);
    }

    /**
     * Gửi một lời gọi đã ký và trả thân JSON. Mỗi lần thử ký lại với timestamp + nonce mới — nonce
     * dùng một lần, gửi lại nonce cũ sẽ bị cổng trả replay_detected.
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $method = strtoupper($method);
        $key = (string) ($this->config['key'] ?? '');
        $secret = (string) ($this->config['secret'] ?? '');

        if ($this->origin() === '' || $key === '' || $secret === '') {
            throw new GatewayException(
                'Chưa cấu hình cổng thanh toán: cần PAYMENT_GATEWAY_URL, PAYMENT_GATEWAY_KEY, PAYMENT_GATEWAY_SECRET.',
                0,
                'not_configured',
            );
        }

        $signedPath = self::API_PREFIX.'/'.ltrim($path, '/');
        $canonical = RequestSignature::canonicalPath($signedPath, $query);
        $body = $payload === null ? '' : $this->encode($payload);
        $url = $this->origin().$canonical;

        $attempts = $method === 'GET' ? 1 + max(0, (int) ($this->config['retries'] ?? 0)) : 1;

        for ($attempt = 1; ; $attempt++) {
            $headers = RequestSignature::headers($key, $secret, $method, $signedPath, $query, $body);

            try {
                $response = $this->send($method, $url, $headers, $body);
                break;
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    throw new GatewayException(
                        'Không kết nối được cổng thanh toán: '.$e->getMessage(),
                        0,
                        'connection_failed',
                        previous: $e,
                    );
                }

                usleep(max(0, (int) ($this->config['retry_sleep_ms'] ?? 0)) * 1000 * $attempt);
            }
        }

        $json = $this->decode($response);

        if (! $response->successful()) {
            throw ErrorMapper::map($response->status(), $json);
        }

        if ($json === null) {
            throw new InvalidPayloadException(
                "Cổng thanh toán trả HTTP {$response->status()} nhưng thân không phải JSON.",
                $response->status(),
            );
        }

        return $json;
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function send(string $method, string $url, array $headers, string $body): Response
    {
        $request = $this->pending()->withHeaders($headers);

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, $url);
    }

    protected function pending(): PendingRequest
    {
        // Lấy Factory từ container mỗi lần để Http::fake() gọi sau khi client đã dựng vẫn ăn.
        $http = $this->http ?? app(HttpFactory::class);

        return $http->acceptJson()
            ->timeout(max(1, (int) ($this->config['timeout'] ?? 15)))
            ->connectTimeout(max(1, (int) ($this->config['connect_timeout'] ?? 5)));
    }

    protected function origin(): string
    {
        return rtrim((string) ($this->config['url'] ?? ''), '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload): string
    {
        // metadata rỗng phải ra {} (đối tượng), không phải [].
        if (array_key_exists('metadata', $payload) && $payload['metadata'] === []) {
            $payload['metadata'] = new \stdClass;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decode(Response $response): ?array
    {
        $raw = $response->body();

        if ($raw === '') {
            return null;
        }

        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * Hợp đồng nói "trả Order" mà không nói có bọc "data" hay không — nhận cả hai.
     *
     * @param  array<string, mixed>  $body
     */
    protected function orderFrom(array $body): Order
    {
        $data = is_array($body['data'] ?? null) && isset($body['data']['ref']) ? $body['data'] : $body;

        return Order::fromArray($data);
    }
}
