<?php

namespace Vju\PaymentClient\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;
use Vju\PaymentClient\PaymentGatewayServiceProvider;
use Vju\SatelliteContracts\Signing\RequestSignature;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    public const URL = 'https://pay.test';

    public const KEY = 'enrollment';

    public const SECRET = 'api-secret-of-enrollment';

    public const WEBHOOK_SECRET = 'webhook-secret-of-enrollment';

    protected function getPackageProviders($app): array
    {
        return [PaymentGatewayServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['PaymentGateway' => \Vju\PaymentClient\Facades\PaymentGateway::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('payment-gateway.url', self::URL);
        $app['config']->set('payment-gateway.key', self::KEY);
        $app['config']->set('payment-gateway.secret', self::SECRET);
        $app['config']->set('payment-gateway.webhook.secret', self::WEBHOOK_SECRET);
        $app['config']->set('payment-gateway.retry_sleep_ms', 0);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        return json_decode($this->fixtureText($name), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function fixtureText(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    /**
     * Ví dụ trong hợp đồng viết "{ …Payment }" / "[ …Payment ]" — ghép Payment mẫu vào cho ra JSON thật.
     *
     * @return array<string, mixed>
     */
    protected function templateFixture(string $name, string $placeholder, string $fixture): array
    {
        $text = str_replace($placeholder, $this->fixtureText($fixture), $this->fixtureText($name));

        return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Kiểm chữ ký của một request đi ra bằng chính RequestSignature::verify, dựng lại đường dẫn chuẩn
     * hoá từ URL thật đã gửi (bỏ phần gốc self::URL).
     */
    protected function assertSignedRequest(Request $request, string $expectedMethod, string $expectedCanonical): void
    {
        $parts = parse_url($request->url());
        $path = $parts['path'] ?? '/';
        parse_str($parts['query'] ?? '', $query);
        $canonical = RequestSignature::canonicalPath($path, $query);

        $this->assertSame($expectedMethod, $request->method());
        $this->assertSame($expectedCanonical, $canonical);
        $this->assertSame(self::KEY, $request->header(RequestSignature::HEADER_KEY)[0] ?? null);

        $timestamp = (int) ($request->header(RequestSignature::HEADER_TIMESTAMP)[0] ?? 0);
        $nonce = (string) ($request->header(RequestSignature::HEADER_NONCE)[0] ?? '');
        $signature = (string) ($request->header(RequestSignature::HEADER_SIGNATURE)[0] ?? '');

        $this->assertTrue(RequestSignature::withinWindow($timestamp));
        $this->assertNotSame('', $nonce);
        $this->assertLessThanOrEqual(RequestSignature::MAX_NONCE_LENGTH, strlen($nonce));
        $this->assertTrue(
            RequestSignature::verify(self::SECRET, $signature, $request->method(), $canonical, $timestamp, $nonce, $request->body()),
            "Chữ ký không khớp với {$request->method()} {$canonical}",
        );
    }

    /**
     * Gửi webhook đã ký như cổng gửi.
     *
     * @param  array<string, mixed>  $overrides  timestamp, nonce, key, secret, signature, path
     */
    protected function deliverWebhook(array|string $envelope, array $overrides = []): TestResponse
    {
        $body = is_string($envelope) ? $envelope : json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = $overrides['path'] ?? '/payment-gateway/webhook';
        $timestamp = $overrides['timestamp'] ?? time();
        $nonce = $overrides['nonce'] ?? RequestSignature::nonce();
        $signature = $overrides['signature'] ?? RequestSignature::sign(
            $overrides['secret'] ?? self::WEBHOOK_SECRET,
            'POST',
            RequestSignature::canonicalPath($path),
            $timestamp,
            $nonce,
            $body,
        );

        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_VJU_KEY' => $overrides['key'] ?? 'payment-gateway',
            'HTTP_X_VJU_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_VJU_NONCE' => $nonce,
            'HTTP_X_VJU_SIGNATURE' => $signature,
        ];

        return $this->call('POST', $path, [], [], [], $server, $body);
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    protected function paidEnvelope(array $payment, ?string $eventId = null): array
    {
        return [
            'id' => $eventId ?? 'evt_'.bin2hex(random_bytes(6)),
            'event' => 'payment.paid',
            'occurred_at' => '2026-10-27T09:41:12+07:00',
            'data' => $payment,
        ];
    }
}
