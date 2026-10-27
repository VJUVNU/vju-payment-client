<?php

namespace Vju\PaymentClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Data\OrderRequest;
use Vju\PaymentClient\Events\OrderExpired;
use Vju\PaymentClient\Events\PaymentReceived;
use Vju\PaymentClient\Exceptions\ConflictException;
use Vju\PaymentClient\Exceptions\NotFoundException;
use Vju\PaymentClient\Exceptions\ProviderUnavailableException;
use Vju\PaymentClient\Facades\PaymentGateway;
use Vju\PaymentClient\Testing\FakeGateway;
use Vju\PaymentClient\Tests\TestCase;

class FakeGatewayTest extends TestCase
{
    public function test_approved_usage_against_fake(): void
    {
        Http::fake();
        $fake = PaymentGateway::fake();

        $don = PaymentGateway::order(
            ref: 'HP42',
            amount: 1500000,
            method: 'bidv',
            customerName: 'Nguyễn Văn B',
            expiresAt: '2026-11-30',
        );

        $this->assertSame('qr', $don->type);
        $this->assertSame('FAKEHP42', $don->accountNo);
        $this->assertNotNull($don->qrImage);
        $this->assertSame(1500000, $don->amountDue);

        $fake->assertOrderCreated('HP42');
        $fake->assertOrderCreated(fn (OrderRequest $r) => $r->amount === 1500000 && $r->expiresAt === '2026-11-30');
        $fake->assertOrderCreatedTimes(1);
        $fake->assertOrderNotCreated('HP43');
        Http::assertNothingSent();
    }

    public function test_fake_is_resolved_through_contract(): void
    {
        $fake = PaymentGateway::fake();

        $this->assertSame($fake, $this->app->make(Gateway::class));
    }

    public function test_redirect_method(): void
    {
        PaymentGateway::fake();

        $don = PaymentGateway::order(ref: 'HP1', amount: 10, method: 'vnpay', customerName: 'A');

        $this->assertSame('redirect', $don->type);
        $this->assertSame('https://payment-gateway.test/checkout/HP1', $don->checkoutUrl);
        $this->assertNull($don->accountNo);
    }

    public function test_assertions_fail_when_not_matching(): void
    {
        $fake = PaymentGateway::fake();

        $this->expectException(AssertionFailedError::class);

        $fake->assertOrderCreated();
    }

    public function test_assert_nothing_sent_fails_after_order(): void
    {
        $fake = PaymentGateway::fake();
        PaymentGateway::order(ref: 'HP1', amount: 10, method: 'bidv', customerName: 'A');

        $this->expectException(AssertionFailedError::class);

        $fake->assertNothingSent();
    }

    public function test_simulate_payment_fires_once_through_dedupe(): void
    {
        $received = [];
        Event::listen(PaymentReceived::class, function (PaymentReceived $e) use (&$received) {
            $received[] = $e;
        });

        $fake = PaymentGateway::fake();
        PaymentGateway::order(ref: 'HP7', amount: 300, method: 'bidv', customerName: 'A');

        $payment = $fake->simulatePayment('HP7');

        $this->assertCount(1, $received);
        $this->assertSame('HP7', $received[0]->ref);
        $this->assertSame(300, $received[0]->amount);
        $this->assertSame($payment->id, $received[0]->transactionId);
        $this->assertSame('fake', $received[0]->source);
        $this->assertSame(1, DB::table('payment_client_events')->where('dedupe_key', 'payment:'.$payment->id)->count());
        $this->assertTrue(PaymentGateway::getOrder('HP7')->isPaid());
        $this->assertSame(300, PaymentGateway::getOrder('HP7')->amountPaid);

        // payment:pull trên bản giả thấy khoản này nhưng không bắn lại.
        $this->artisan('payment:pull')->assertSuccessful();
        $this->assertCount(1, $received);
    }

    public function test_partial_payments(): void
    {
        Event::fake([PaymentReceived::class]);
        $fake = PaymentGateway::fake();
        PaymentGateway::order(ref: 'HP8', amount: 1000, method: 'bidv', customerName: 'A');

        $fake->simulatePayment('HP8', 400);
        $this->assertSame('open', PaymentGateway::getOrder('HP8')->status);
        $this->assertSame(600, PaymentGateway::getOrder('HP8')->remaining());

        $fake->simulatePayment('HP8');
        $this->assertTrue(PaymentGateway::getOrder('HP8')->isPaid());

        Event::assertDispatchedTimes(PaymentReceived::class, 2);
    }

    public function test_simulate_payment_overrides(): void
    {
        Event::fake([PaymentReceived::class]);
        $fake = PaymentGateway::fake();

        $payment = $fake->simulatePayment('HP9', 50, ['provider_transaction_id' => 'FT1', 'metadata' => ['student_code' => 'x']]);

        $this->assertSame('FT1', $payment->providerTransactionId);
        Event::assertDispatched(PaymentReceived::class, fn (PaymentReceived $e) => $e->metadata === ['student_code' => 'x']);
    }

    public function test_contract_rules_for_paid_orders(): void
    {
        Event::fake([PaymentReceived::class]);
        $fake = PaymentGateway::fake();
        PaymentGateway::order(ref: 'HP5', amount: 100, method: 'bidv', customerName: 'A');
        $fake->simulatePayment('HP5');

        // POST lại đơn đã thu: trả nguyên, không đổi.
        $again = PaymentGateway::order(ref: 'HP5', amount: 999, method: 'bidv', customerName: 'A');
        $this->assertSame(100, $again->amountDue);
        $this->assertTrue($again->isPaid());

        try {
            PaymentGateway::cancelOrder('HP5');
            $this->fail('Huỷ đơn đã thu phải ném ConflictException.');
        } catch (ConflictException $e) {
            $this->assertTrue($e->isAlreadyPaid());
        }

        try {
            PaymentGateway::updateOrder('HP5', amount: 5);
            $this->fail('Sửa đơn đã thu phải ném ConflictException.');
        } catch (ConflictException $e) {
            $this->assertTrue($e->isAlreadyPaid());
        }
    }

    public function test_update_cancel_and_reopen(): void
    {
        $fake = PaymentGateway::fake();
        PaymentGateway::order(ref: 'HP6', amount: 100, method: 'bidv', customerName: 'A');

        $updated = PaymentGateway::updateOrder('HP6', amount: 80, description: 'Giảm trừ');
        $this->assertSame(80, $updated->amountDue);
        $fake->assertOrderUpdated('HP6', fn (array $c) => $c['amount'] === 80);

        $this->assertSame('cancelled', PaymentGateway::cancelOrder('HP6')->status);
        $fake->assertOrderCancelled('HP6');

        try {
            PaymentGateway::updateOrder('HP6', amount: 70);
            $this->fail('Sửa đơn đã huỷ phải ném order_closed.');
        } catch (ConflictException $e) {
            $this->assertTrue($e->isOrderClosed());
        }

        // POST lại đơn đã huỷ: mở lại.
        $this->assertTrue(PaymentGateway::order(ref: 'HP6', amount: 70, method: 'bidv', customerName: 'A')->isOpen());
    }

    public function test_unknown_order_is_not_found(): void
    {
        PaymentGateway::fake();

        $this->expectException(NotFoundException::class);

        PaymentGateway::getOrder('HP404');
    }

    public function test_fail_next(): void
    {
        $fake = PaymentGateway::fake();
        $fake->failNext(new ProviderUnavailableException('BIDV bảo trì', 503, 'provider_unavailable'));

        try {
            PaymentGateway::order(ref: 'HP1', amount: 10, method: 'bidv', customerName: 'A');
            $this->fail('Phải ném lỗi đã cài.');
        } catch (ProviderUnavailableException) {
        }

        $this->assertSame('open', PaymentGateway::order(ref: 'HP1', amount: 10, method: 'bidv', customerName: 'A')->status);
    }

    public function test_simulate_expired_fires_order_expired(): void
    {
        Event::fake([OrderExpired::class]);
        $fake = PaymentGateway::fake();
        PaymentGateway::order(ref: 'HP3', amount: 10, method: 'bidv', customerName: 'A');

        $fake->simulateExpired('HP3');

        Event::assertDispatchedTimes(OrderExpired::class, 1);
        Event::assertDispatched(OrderExpired::class, fn (OrderExpired $e) => $e->ref === 'HP3' && $e->order->status === 'expired');
    }

    public function test_payments_paging_on_fake(): void
    {
        Event::fake([PaymentReceived::class]);
        $fake = PaymentGateway::fake();

        foreach (range(1, 3) as $i) {
            $fake->simulatePayment('HP'.$i, 10);
        }

        $page = PaymentGateway::payments(0, 2);
        $this->assertSame([1, 2], array_map(fn ($p) => $p->id, $page->data));
        $this->assertTrue($page->hasMore);
        $this->assertSame(2, $page->nextCursor);

        $page = PaymentGateway::payments(2, 2);
        $this->assertSame([3], array_map(fn ($p) => $p->id, $page->data));
        $this->assertFalse($page->hasMore);
    }

    public function test_fake_class_is_returned(): void
    {
        $this->assertInstanceOf(FakeGateway::class, PaymentGateway::fake());
    }
}
