<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Vju\PaymentClient\Client\GatewayClient;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Exceptions\AuthenticationException;
use Vju\PaymentClient\Exceptions\ConflictException;
use Vju\PaymentClient\Exceptions\GatewayException;
use Vju\PaymentClient\Exceptions\InvalidPayloadException;
use Vju\PaymentClient\Exceptions\NotFoundException;
use Vju\PaymentClient\Exceptions\ProviderUnavailableException;
use Vju\PaymentClient\Exceptions\ValidationException;
use Vju\PaymentClient\Facades\PaymentGateway;
use Vju\PaymentClient\Tests\TestCase;

class GatewayClientTest extends TestCase
{
    public function test_approved_usage_creates_order_with_signed_post(): void
    {
        Http::fake([self::URL.'/api/v1/orders' => Http::response($this->fixture('order.json'), 201)]);

        $don = PaymentGateway::order(
            ref: 'HP1234',
            amount: 1200000,
            method: 'bidv',
            customerName: 'Nguyễn Văn A',
            expiresAt: new \DateTimeImmutable('2026-10-31', new \DateTimeZone('Asia/Ho_Chi_Minh')),
        );

        $this->assertSame('qr', $don->type);
        $this->assertSame('V3CNTHP1234', $don->accountNo);
        $this->assertSame('data:image/png;base64,…', $don->qrImage);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'POST', '/api/v1/orders');
            $this->assertSame('application/json', $request->header('Content-Type')[0] ?? null);
            $this->assertSame([
                'ref' => 'HP1234',
                'method' => 'bidv',
                'amount' => 1200000,
                'customer_name' => 'Nguyễn Văn A',
                'expires_at' => '2026-10-31',
            ], json_decode($request->body(), true));

            return true;
        });
    }

    public function test_all_named_fields_are_sent(): void
    {
        Http::fake([self::URL.'/api/v1/orders' => Http::response($this->fixture('order.json'), 200)]);

        PaymentGateway::order(
            ref: 'HP1234',
            amount: 1200000,
            method: 'bidv',
            customerName: 'Nguyễn Văn A',
            description: 'Học phí đợt 1',
            items: [['label' => 'Học phí', 'amount' => 1200000]],
            payCode: 'HP1234',
            priority: 100,
            validFrom: '2026-10-01',
            expiresAt: '2026-10-31',
            customerEmail: 'a@vju.ac.vn',
            metadata: [],
        );

        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'POST', '/api/v1/orders');
            // metadata rỗng phải đi ra dạng đối tượng {} như ví dụ hợp đồng.
            $this->assertStringContainsString('"metadata":{}', $request->body());
            $this->assertSame($this->fixture('order-request.json'), json_decode($request->body(), true));

            return true;
        });
    }

    public function test_default_method_from_config(): void
    {
        config(['payment-gateway.default_method' => 'shb']);
        $this->app->forgetInstance(GatewayClient::class);
        $this->app->forgetInstance(Gateway::class);
        PaymentGateway::clearResolvedInstances();

        Http::fake([self::URL.'/*' => Http::response($this->fixture('order.json'), 201)]);

        PaymentGateway::order(ref: 'HP1', amount: 5, customerName: 'A');

        Http::assertSent(fn (Request $r) => json_decode($r->body(), true)['method'] === 'shb');
    }

    public function test_get_order_signed_with_empty_body(): void
    {
        Http::fake([self::URL.'/api/v1/orders/HP1234' => Http::response($this->fixture('order.json'))]);

        $order = PaymentGateway::getOrder('HP1234');

        $this->assertSame('HP1234', $order->ref);
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'GET', '/api/v1/orders/HP1234');
            $this->assertSame('', $request->body());

            return true;
        });
    }

    public function test_resource_wrapped_in_data_is_accepted(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['data' => $this->fixture('order.json')])]);

        $this->assertSame('V3CNTHP1234', PaymentGateway::getOrder('HP1234')->accountNo);
    }

    public function test_update_order_sends_only_given_fields(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->fixture('order.json'))]);

        PaymentGateway::updateOrder('HP1234', amount: 900000, expiresAt: '2026-11-15');

        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'PATCH', '/api/v1/orders/HP1234');
            $this->assertSame(['amount' => 900000, 'expires_at' => '2026-11-15'], json_decode($request->body(), true));

            return true;
        });
    }

    public function test_cancel_order_patches_status(): void
    {
        $cancelled = array_merge($this->fixture('order.json'), ['status' => 'cancelled']);
        Http::fake([self::URL.'/*' => Http::response($cancelled)]);

        $order = PaymentGateway::cancelOrder('HP1234');

        $this->assertSame(Order::STATUS_CANCELLED, $order->status);
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'PATCH', '/api/v1/orders/HP1234');
            $this->assertSame('{"status":"cancelled"}', $request->body());

            return true;
        });
    }

    public function test_payments_signs_sorted_query(): void
    {
        Http::fake([self::URL.'/*' => Http::response(
            $this->templateFixture('payments-page.template.txt', '…Payment', 'payment.json'),
        )]);

        $page = PaymentGateway::payments(cursor: 5000, limit: 50);

        $this->assertSame(5012, $page->nextCursor);
        $this->assertSame(5012, $page->data[0]->id);
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'GET', '/api/v1/payments?cursor=5000&limit=50');

            return true;
        });
    }

    public function test_payments_limit_is_clamped_to_contract_maximum(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['data' => [], 'next_cursor' => 0, 'has_more' => false])]);

        PaymentGateway::payments(0, 10000);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/payments?cursor=0&limit=500'));
    }

    public function test_reconciliation_signs_date_query(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->fixture('reconciliation.json'))]);

        $rec = PaymentGateway::reconciliation(new \DateTimeImmutable('2026-10-26 10:00', new \DateTimeZone('Asia/Ho_Chi_Minh')));

        $this->assertSame('2026-10-26', $rec->date);
        $this->assertSame(120, $rec->runs[0]->matched);
        Http::assertSent(function (Request $request) {
            $this->assertSignedRequest($request, 'GET', '/api/v1/reconciliation?date=2026-10-26');

            return true;
        });
    }

    public function test_url_with_sub_path_still_signs_api_path(): void
    {
        config(['payment-gateway.url' => 'https://host.test/gateway/']);
        $this->app->forgetInstance(GatewayClient::class);
        $this->app->forgetInstance(Gateway::class);
        PaymentGateway::clearResolvedInstances();

        Http::fake(['https://host.test/gateway/api/v1/orders/HP1' => Http::response($this->fixture('order.json'))]);

        PaymentGateway::getOrder('HP1');

        Http::assertSent(function (Request $request) {
            $this->assertSame('https://host.test/gateway/api/v1/orders/HP1', $request->url());
            $sig = $request->header('X-Vju-Signature')[0];
            $ts = (int) $request->header('X-Vju-Timestamp')[0];
            $nonce = $request->header('X-Vju-Nonce')[0];
            $this->assertTrue(\Vju\SatelliteContracts\Signing\RequestSignature::verify(
                self::SECRET, $sig, 'GET', '/api/v1/orders/HP1', $ts, $nonce, '',
            ));

            return true;
        });
    }

    public function test_get_is_retried_on_connection_error_with_fresh_nonce(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->push($this->fixture('order.json')),
        ]);

        $this->assertSame('HP1234', PaymentGateway::getOrder('HP1234')->ref);

        $nonces = [];
        Http::assertSent(function (Request $request) use (&$nonces) {
            $this->assertSignedRequest($request, 'GET', '/api/v1/orders/HP1234');
            $nonces[] = $request->header('X-Vju-Nonce')[0];

            return true;
        });
        Http::assertSentCount(3);
        $this->assertCount(3, array_unique($nonces));
    }

    public function test_get_gives_up_after_configured_retries(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()
            ->pushFailedConnection()->pushFailedConnection()->pushFailedConnection()->pushFailedConnection(),
        ]);

        try {
            PaymentGateway::getOrder('HP1234');
            $this->fail('Phải ném GatewayException.');
        } catch (GatewayException $e) {
            $this->assertSame('connection_failed', $e->errorCode);
            $this->assertSame(0, $e->status);
        }

        Http::assertSentCount(3);
    }

    public function test_post_is_not_retried_on_connection_error(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()->pushFailedConnection()->push($this->fixture('order.json'))]);

        try {
            PaymentGateway::order(ref: 'HP1', amount: 1, method: 'bidv', customerName: 'A');
            $this->fail('Phải ném GatewayException.');
        } catch (GatewayException $e) {
            $this->assertSame('connection_failed', $e->errorCode);
        }

        Http::assertSentCount(1);
    }

    public function test_http_errors_are_not_retried(): void
    {
        Http::fake([self::URL.'/*' => Http::response(['error' => ['code' => 'order_not_found', 'message' => 'x']], 404)]);

        try {
            PaymentGateway::getOrder('HP9');
        } catch (NotFoundException) {
        }

        Http::assertSentCount(1);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: class-string}>
     */
    public static function documentedErrors(): array
    {
        return [
            'unknown_merchant' => [401, 'unknown_merchant', AuthenticationException::class],
            'signature_expired' => [401, 'signature_expired', AuthenticationException::class],
            'nonce_missing' => [401, 'nonce_missing', AuthenticationException::class],
            'signature_invalid' => [401, 'signature_invalid', AuthenticationException::class],
            'replay_detected' => [401, 'replay_detected', AuthenticationException::class],
            'merchant_disabled' => [403, 'merchant_disabled', AuthenticationException::class],
            'ip_not_allowed' => [403, 'ip_not_allowed', AuthenticationException::class],
            'order_not_found' => [404, 'order_not_found', NotFoundException::class],
            'already_paid' => [409, 'already_paid', ConflictException::class],
            'order_closed' => [409, 'order_closed', ConflictException::class],
            'validation_failed' => [422, 'validation_failed', ValidationException::class],
            'method_not_enabled' => [422, 'method_not_enabled', ValidationException::class],
            'ref_not_allowed' => [422, 'ref_not_allowed', ValidationException::class],
            'provider_unavailable' => [503, 'provider_unavailable', ProviderUnavailableException::class],
            'mã lạ 409' => [409, 'something_new', ConflictException::class],
            'mã lạ 500' => [500, 'boom', GatewayException::class],
        ];
    }

    #[DataProvider('documentedErrors')]
    public function test_error_envelope_maps_to_typed_exception(int $status, string $code, string $class): void
    {
        Http::fake([self::URL.'/*' => Http::response(['error' => ['code' => $code, 'message' => "Lỗi {$code}"]], $status)]);

        try {
            PaymentGateway::getOrder('HP1234');
            $this->fail('Phải ném exception.');
        } catch (GatewayException $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->errorCode);
            $this->assertTrue($e->is($code));
            $this->assertSame("Lỗi {$code}", $e->getMessage());
        }
    }

    public function test_validation_error_carries_fields_from_contract_example(): void
    {
        Http::fake([self::URL.'/*' => Http::response($this->fixture('error.json'), 422)]);

        try {
            PaymentGateway::order(ref: 'HP1', amount: 1, method: 'bidv', customerName: 'A');
            $this->fail('Phải ném ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame(['amount' => ['…']], $e->fields);
            $this->assertSame(['…'], $e->messagesFor('amount'));
            $this->assertSame([], $e->messagesFor('ref'));
        }
    }

    public function test_conflict_helpers(): void
    {
        Http::fake([self::URL.'/*' => Http::sequence()
            ->push(['error' => ['code' => 'already_paid', 'message' => 'x']], 409)
            ->push(['error' => ['code' => 'order_closed', 'message' => 'x']], 409),
        ]);

        try {
            PaymentGateway::cancelOrder('HP1');
        } catch (ConflictException $e) {
            $this->assertTrue($e->isAlreadyPaid());
            $this->assertFalse($e->isOrderClosed());
        }

        try {
            PaymentGateway::updateOrder('HP1', amount: 5);
        } catch (ConflictException $e) {
            $this->assertTrue($e->isOrderClosed());
        }
    }

    public function test_provider_unavailable_exposes_order_when_sent(): void
    {
        Http::fake([self::URL.'/*' => Http::response([
            'error' => ['code' => 'provider_unavailable', 'message' => 'BIDV đang bảo trì'],
            'data' => array_merge($this->fixture('order.json'), ['payment' => null]),
        ], 503)]);

        try {
            PaymentGateway::order(ref: 'HP1234', amount: 1200000, method: 'bidv', customerName: 'A');
            $this->fail('Phải ném ProviderUnavailableException.');
        } catch (ProviderUnavailableException $e) {
            $this->assertSame('HP1234', $e->order()?->ref);
            $this->assertNull($e->order()?->payment);
        }
    }

    public function test_non_json_error_falls_back_to_status(): void
    {
        Http::fake([self::URL.'/*' => Http::response('<html>Bad gateway</html>', 502)]);

        try {
            PaymentGateway::getOrder('HP1');
            $this->fail('Phải ném GatewayException.');
        } catch (GatewayException $e) {
            $this->assertSame(GatewayException::class, $e::class);
            $this->assertSame(502, $e->status);
            $this->assertNull($e->errorCode);
        }
    }

    public function test_non_json_success_is_invalid_payload(): void
    {
        Http::fake([self::URL.'/*' => Http::response('ok', 200)]);

        $this->expectException(InvalidPayloadException::class);

        PaymentGateway::getOrder('HP1');
    }

    public function test_missing_configuration_fails_before_sending(): void
    {
        config(['payment-gateway.secret' => null]);
        $this->app->forgetInstance(GatewayClient::class);
        $this->app->forgetInstance(Gateway::class);
        PaymentGateway::clearResolvedInstances();
        Http::fake();

        try {
            PaymentGateway::getOrder('HP1');
            $this->fail('Phải ném GatewayException.');
        } catch (GatewayException $e) {
            $this->assertSame('not_configured', $e->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_health_is_unsigned(): void
    {
        Http::fake([self::URL.'/api/health' => Http::response($this->fixture('health.json'))]);

        $health = $this->app->make(GatewayClient::class)->health();

        $this->assertSame('ok', $health['status']);
        $this->assertSame('disabled', $health['checks']['providers']['shb']);
        Http::assertSent(fn (Request $r) => ! $r->hasHeader('X-Vju-Signature'));
    }
}
