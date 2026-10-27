<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Vju\PaymentClient\Events\OrderExpired;
use Vju\PaymentClient\Events\PaymentReceived;
use Vju\PaymentClient\Tests\TestCase;
use Vju\SatelliteContracts\Signing\RequestSignature;

class WebhookTest extends TestCase
{
    /** @var list<PaymentReceived> */
    private array $received = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(PaymentReceived::class, function (PaymentReceived $e) {
            $this->received[] = $e;
        });
    }

    private function contractEnvelope(): array
    {
        return $this->templateFixture('webhook-payment-paid.template.txt', '{ …Payment }', 'payment.json');
    }

    public function test_valid_webhook_from_contract_example_fires_once(): void
    {
        $this->deliverWebhook($this->contractEnvelope())
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $this->assertCount(1, $this->received);
        $e = $this->received[0];
        $this->assertSame('HP1234', $e->ref);
        $this->assertSame('HP1234', $e->payCode);
        $this->assertSame(1200000, $e->amount);
        $this->assertSame(5012, $e->transactionId);
        $this->assertSame('FT26300123456', $e->providerTransactionId);
        $this->assertSame('bidv', $e->method);
        $this->assertSame('2026-10-27T09:41:12+07:00', $e->paidAt?->toIso8601String());
        $this->assertSame(['student_code' => '22110001'], $e->metadata);
        $this->assertSame('webhook', $e->source);
        $this->assertSame('evt_01JB…', $e->eventId);

        $row = DB::table('payment_client_events')->first();
        $this->assertSame('payment:5012', $row->dedupe_key);
        $this->assertSame(5012, (int) $row->gateway_payment_id);
        $this->assertSame('webhook', $row->source);
    }

    public function test_bad_signature_is_rejected_without_event(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['secret' => 'wrong'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'signature_invalid');

        $this->assertSame([], $this->received);
        $this->assertSame(0, DB::table('payment_client_events')->count());
    }

    public function test_tampered_body_is_rejected(): void
    {
        $envelope = $this->contractEnvelope();
        $body = json_encode($envelope);
        $nonce = RequestSignature::nonce();
        $ts = time();
        $sig = RequestSignature::sign(self::WEBHOOK_SECRET, 'POST', '/payment-gateway/webhook', $ts, $nonce, $body);
        $envelope['data']['amount'] = 99999999;

        $this->deliverWebhook($envelope, ['signature' => $sig, 'nonce' => $nonce, 'timestamp' => $ts])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'signature_invalid');

        $this->assertSame([], $this->received);
    }

    public function test_expired_timestamp_is_rejected(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['timestamp' => time() - 301])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'signature_expired');

        $this->deliverWebhook($this->contractEnvelope(), ['timestamp' => time() + 301])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'signature_expired');

        $this->assertSame([], $this->received);
    }

    public function test_timestamp_inside_window_is_accepted(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['timestamp' => time() - 290])->assertOk();

        $this->assertCount(1, $this->received);
    }

    public function test_replayed_nonce_is_rejected(): void
    {
        $overrides = ['nonce' => 'n-replay', 'timestamp' => time()];

        $this->deliverWebhook($this->contractEnvelope(), $overrides)->assertOk();
        $this->deliverWebhook($this->contractEnvelope(), $overrides)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'replay_detected');

        $this->assertCount(1, $this->received);
    }

    public function test_missing_or_long_nonce_is_rejected(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['nonce' => ''])
            ->assertStatus(401)->assertJsonPath('error.code', 'nonce_missing');
        $this->deliverWebhook($this->contractEnvelope(), ['nonce' => str_repeat('a', 65)])
            ->assertStatus(401)->assertJsonPath('error.code', 'nonce_missing');

        $this->assertSame([], $this->received);
    }

    public function test_wrong_sender_key_is_rejected(): void
    {
        $this->deliverWebhook($this->contractEnvelope(), ['key' => 'einvoice'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unknown_sender');

        $this->assertSame([], $this->received);
    }

    public function test_unconfigured_secret_refuses_with_500(): void
    {
        config(['payment-gateway.webhook.secret' => null]);

        $this->deliverWebhook($this->contractEnvelope())
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'webhook_not_configured');

        $this->assertSame([], $this->received);
    }

    public function test_rotated_secrets_are_all_accepted(): void
    {
        config(['payment-gateway.webhook.secret' => 'new-secret, '.self::WEBHOOK_SECRET]);

        $this->deliverWebhook($this->paidEnvelope(['id' => 1, 'ref' => 'HP1', 'amount' => 10]), ['secret' => 'new-secret'])->assertOk();
        $this->deliverWebhook($this->paidEnvelope(['id' => 2, 'ref' => 'HP2', 'amount' => 10]))->assertOk();

        $this->assertCount(2, $this->received);
    }

    public function test_duplicate_delivery_fires_once(): void
    {
        $payment = $this->fixture('payment.json');

        // Cổng gửi lại: cùng data.id, nonce/timestamp/id sự kiện mới.
        $this->deliverWebhook($this->paidEnvelope($payment, 'evt_a'))->assertOk()->assertJson(['status' => 'processed']);
        $this->deliverWebhook($this->paidEnvelope($payment, 'evt_b'))->assertOk()->assertJson(['status' => 'duplicate']);
        $this->deliverWebhook($this->paidEnvelope($payment, 'evt_a'))->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->received);
        $this->assertSame(1, DB::table('payment_client_events')->count());
    }

    public function test_listener_failure_returns_500_and_rolls_back_dedupe(): void
    {
        $fail = true;
        Event::listen(PaymentReceived::class, function (PaymentReceived $e) use (&$fail) {
            DB::table('payment_client_cursors')->insert(['name' => 'listener-side-effect', 'cursor' => $e->transactionId]);

            if ($fail) {
                throw new RuntimeException('CSDL hoá đơn đang khoá');
            }
        });

        $this->deliverWebhook($this->contractEnvelope())
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'listener_failed');

        $this->assertSame(0, DB::table('payment_client_events')->count());
        // Việc ghi của listener cũng rollback cùng dòng chống trùng.
        $this->assertSame(0, DB::table('payment_client_cursors')->where('name', 'listener-side-effect')->count());

        $fail = false;
        $this->deliverWebhook($this->contractEnvelope())->assertOk()->assertJson(['status' => 'processed']);

        // Listener ghi nhận của bài kiểm chạy cả hai lần (lần đầu trước khi listener kia ném).
        $this->assertCount(2, $this->received);
        $this->assertSame(1, DB::table('payment_client_events')->count());
        $this->assertSame(1, DB::table('payment_client_cursors')->where('name', 'listener-side-effect')->count());

        $this->deliverWebhook($this->contractEnvelope())->assertOk()->assertJson(['status' => 'duplicate']);
        $this->assertCount(2, $this->received);
    }

    public function test_order_expired_fires_once_per_event_id(): void
    {
        $expired = [];
        Event::listen(OrderExpired::class, function (OrderExpired $e) use (&$expired) {
            $expired[] = $e;
        });

        $envelope = [
            'id' => 'evt_exp_1',
            'event' => 'order.expired',
            'occurred_at' => '2026-11-01T00:00:00+07:00',
            'data' => array_merge($this->fixture('order.json'), ['status' => 'expired']),
        ];

        $this->deliverWebhook($envelope)->assertOk()->assertJson(['status' => 'processed']);
        $this->deliverWebhook($envelope)->assertOk()->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $expired);
        $this->assertSame('HP1234', $expired[0]->ref);
        $this->assertSame('expired', $expired[0]->order->status);
        $this->assertSame('evt_exp_1', $expired[0]->eventId);
        $this->assertSame([], $this->received);
    }

    public function test_unknown_event_is_acknowledged_and_ignored(): void
    {
        $this->deliverWebhook(['id' => 'evt_x', 'event' => 'order.refunded', 'data' => ['ref' => 'HP1']])
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame(0, DB::table('payment_client_events')->count());
    }

    public function test_signed_but_malformed_payload_is_422(): void
    {
        $this->deliverWebhook('not json')->assertStatus(422)->assertJsonPath('error.code', 'invalid_payload');
        $this->deliverWebhook($this->paidEnvelope(['ref' => 'HP1', 'amount' => 5]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_payload');

        $this->assertSame([], $this->received);
    }

    public function test_get_is_not_routed(): void
    {
        $this->get('/payment-gateway/webhook')->assertStatus(405);
    }

    public function test_old_nonces_are_pruned(): void
    {
        DB::table('payment_client_nonces')->insert([
            'sender' => 'payment-gateway', 'nonce' => 'n-old', 'used_at' => now()->subHour(),
        ]);

        $this->deliverWebhook($this->contractEnvelope())->assertOk();

        $this->assertSame(0, DB::table('payment_client_nonces')->where('nonce', 'n-old')->count());
        $this->assertSame(1, DB::table('payment_client_nonces')->count());
    }
}
