<?php

namespace Vju\PaymentClient\Tests\Unit;

use InvalidArgumentException;
use Vju\PaymentClient\Data\Discrepancy;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Data\OrderRequest;
use Vju\PaymentClient\Data\Payment;
use Vju\PaymentClient\Data\PaymentPage;
use Vju\PaymentClient\Data\Reconciliation;
use Vju\PaymentClient\Exceptions\InvalidPayloadException;
use Vju\PaymentClient\Tests\TestCase;

class DataParsingTest extends TestCase
{
    public function test_order_example_from_contract(): void
    {
        $order = Order::fromArray($this->fixture('order.json'));

        $this->assertSame('HP1234', $order->ref);
        $this->assertSame('HP1234', $order->payCode);
        $this->assertSame('bidv', $order->method);
        $this->assertSame('qr', $order->type);
        $this->assertTrue($order->isQr());
        $this->assertSame('open', $order->status);
        $this->assertTrue($order->isOpen());
        $this->assertSame(1200000, $order->amountDue);
        $this->assertSame(0, $order->amountPaid);
        $this->assertSame(1200000, $order->remaining());
        $this->assertSame('VND', $order->currency);
        $this->assertSame('Nguyễn Văn A', $order->customerName);
        $this->assertSame('Học phí đợt 1 năm học 2026-2027', $order->description);
        $this->assertCount(1, $order->items);
        $this->assertSame('Học phí', $order->items[0]->label);
        $this->assertSame(1200000, $order->items[0]->amount);
        $this->assertSame(100, $order->priority);
        $this->assertSame('2026-10-01', $order->validFrom?->format('Y-m-d'));
        $this->assertSame('2026-10-31', $order->expiresAt?->format('Y-m-d'));
        $this->assertSame('Asia/Ho_Chi_Minh', $order->expiresAt?->getTimezone()->getName());

        $this->assertNotNull($order->payment);
        $this->assertSame('BIDV', $order->bankCode);
        $this->assertSame('V3CNTHP1234', $order->accountNo);
        $this->assertSame('TRUONG DAI HOC VIET NHAT', $order->accountName);
        $this->assertSame('00020101021238…', $order->qrString);
        $this->assertSame('data:image/png;base64,…', $order->qrImage);
        $this->assertNull($order->checkoutUrl);
        $this->assertSame('2026-10-27T09:30:00+07:00', $order->payment->issuedAt?->toIso8601String());

        $this->assertSame(['student_code' => '22110001'], $order->metadata);
        // Ví dụ để "…" ở created_at — đọc khoan dung ra null chứ không ném lỗi.
        $this->assertNull($order->createdAt);
        $this->assertSame($this->fixture('order.json'), $order->raw);
    }

    public function test_order_without_payment_info(): void
    {
        $data = $this->fixture('order.json');
        $data['payment'] = null;
        $data['type'] = 'redirect';

        $order = Order::fromArray($data);

        $this->assertNull($order->payment);
        $this->assertFalse($order->hasPaymentInfo());
        $this->assertNull($order->accountNo);
        $this->assertNull($order->checkoutUrl);
        $this->assertTrue($order->isRedirect());
    }

    public function test_order_requires_ref(): void
    {
        $this->expectException(InvalidPayloadException::class);

        Order::fromArray(['status' => 'open']);
    }

    public function test_payment_example_from_contract(): void
    {
        $payment = Payment::fromArray($this->fixture('payment.json'));

        $this->assertSame(5012, $payment->id);
        $this->assertSame('HP1234', $payment->ref);
        $this->assertSame('HP1234', $payment->payCode);
        $this->assertSame('bidv', $payment->method);
        $this->assertSame(1200000, $payment->amount);
        $this->assertSame('FT26300123456', $payment->providerTransactionId);
        $this->assertSame('2026-10-27T09:41:12+07:00', $payment->paidAt?->toIso8601String());
        $this->assertSame('HP1234 thanh toan hoc phi', $payment->payerNote);
        $this->assertSame(['student_code' => '22110001'], $payment->metadata);
    }

    public function test_payment_requires_id_and_amount(): void
    {
        foreach (['id', 'amount'] as $field) {
            $data = $this->fixture('payment.json');
            unset($data[$field]);

            try {
                Payment::fromArray($data);
                $this->fail("Thiếu {$field} mà không ném lỗi.");
            } catch (InvalidPayloadException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_payment_page_example_from_contract(): void
    {
        $page = PaymentPage::fromArray($this->templateFixture('payments-page.template.txt', '…Payment', 'payment.json'));

        $this->assertCount(1, $page->data);
        $this->assertSame(5012, $page->data[0]->id);
        $this->assertSame(5012, $page->nextCursor);
        $this->assertFalse($page->hasMore);
    }

    public function test_empty_page_keeps_cursor(): void
    {
        $page = PaymentPage::fromArray(['data' => [], 'has_more' => false], 77);

        $this->assertSame([], $page->data);
        $this->assertSame(77, $page->nextCursor);
    }

    public function test_reconciliation_example_from_contract(): void
    {
        $rec = Reconciliation::fromArray($this->fixture('reconciliation.json')['data']);

        $this->assertSame('2026-10-26', $rec->date);
        $this->assertCount(1, $rec->runs);
        $this->assertSame('bidv', $rec->runs[0]->provider);
        $this->assertSame('completed', $rec->runs[0]->status);
        $this->assertSame(120, $rec->runs[0]->matched);
        $this->assertSame(1, $rec->runs[0]->mismatched);
        $this->assertCount(1, $rec->discrepancies);

        $d = $rec->discrepancies[0];
        $this->assertSame('bidv', $d->provider);
        $this->assertSame(Discrepancy::MISSING_IN_GATEWAY, $d->kind);
        $this->assertSame('FT…', $d->providerTransactionId);
        $this->assertSame(500000, $d->amount);
        $this->assertSame('HP99', $d->payCode);
        $this->assertFalse($d->resolved);
        $this->assertCount(1, $rec->unresolved());
    }

    public function test_order_request_matches_contract_body(): void
    {
        $request = OrderRequest::make(
            ref: 'HP1234',
            amount: 1200000,
            method: 'bidv',
            customerName: 'Nguyễn Văn A',
            description: 'Học phí đợt 1',
            items: [['label' => 'Học phí', 'amount' => 1200000]],
            payCode: 'HP1234',
            priority: 100,
            validFrom: new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('Asia/Ho_Chi_Minh')),
            expiresAt: '2026-10-31',
            customerEmail: 'a@vju.ac.vn',
            metadata: [],
        );

        $this->assertSame($this->fixture('order-request.json'), $request->toArray());
    }

    public function test_legacy_pay_code_is_not_format_checked(): void
    {
        $request = OrderRequest::make(ref: 'HP1', amount: 1, method: 'bidv', customerName: 'A', payCode: 'SV-2211-0001-KHOA-CU');

        $this->assertSame('SV-2211-0001-KHOA-CU', $request->toArray()['pay_code']);
    }

    public function test_date_converted_to_hanoi_day(): void
    {
        // 23:30 UTC ngày 31/10 là 06:30 sáng 01/11 ở Hà Nội.
        $request = OrderRequest::make(
            ref: 'HP1', amount: 1, method: 'bidv', customerName: 'A',
            expiresAt: new \DateTimeImmutable('2026-10-31 23:30:00', new \DateTimeZone('UTC')),
        );

        $this->assertSame('2026-11-01', $request->expiresAt);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidRequests(): array
    {
        return [
            'ref quá dài' => [['ref' => 'HP123456789012345']],
            'ref có ký tự lạ' => [['ref' => 'HP-1']],
            'amount = 0' => [['amount' => 0]],
            'thiếu method' => [['method' => '']],
            'thiếu customer_name' => [['customerName' => ' ']],
            'tổng items lệch' => [['items' => [['label' => 'x', 'amount' => 1]]]],
            'pay_code rỗng' => [['payCode' => ' ']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function test_order_request_rejects_programmer_errors(array $override): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderRequest::make(...array_merge(['ref' => 'HP1', 'amount' => 100, 'method' => 'bidv', 'customerName' => 'A'], $override));
    }
}
