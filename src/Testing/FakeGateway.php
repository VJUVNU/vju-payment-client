<?php

namespace Vju\PaymentClient\Testing;

use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use Throwable;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Data\OrderRequest;
use Vju\PaymentClient\Data\Payment;
use Vju\PaymentClient\Data\PaymentPage;
use Vju\PaymentClient\Data\Reconciliation;
use Vju\PaymentClient\Exceptions\ConflictException;
use Vju\PaymentClient\Exceptions\NotFoundException;
use Vju\PaymentClient\Support\Cast;
use Vju\PaymentClient\Support\Inbox;
use Vju\PaymentClient\Support\OrderChanges;

/**
 * Cổng giả trong bộ nhớ cho kiểm thử của app: PaymentGateway::fake().
 *
 * Làm theo luật của hợp đồng (upsert theo ref, đơn đã thu thì POST trả nguyên, PATCH đơn đã thu →
 * already_paid, PATCH đơn đã huỷ/hết hạn → order_closed). simulatePayment() bắn PaymentReceived qua
 * đúng đường chống trùng của webhook, nên cần chạy migration của gói trong CSDL kiểm thử.
 */
class FakeGateway implements Gateway
{
    /** @var array<string, array<string, mixed>> */
    protected array $orders = [];

    /** @var list<OrderRequest> */
    protected array $created = [];

    /** @var list<array{ref: string, changes: array<string, mixed>}> */
    protected array $updated = [];

    /** @var list<string> */
    protected array $cancelled = [];

    /** @var list<Payment> */
    protected array $payments = [];

    /** @var list<Throwable> */
    protected array $failures = [];

    /** @var list<string> */
    protected array $redirectMethods = ['vnpay'];

    protected int $nextPaymentId = 1;

    protected int $nextEventId = 1;

    protected ?Reconciliation $reconciliation = null;

    public function __construct(
        protected Inbox $inbox,
        protected ?string $defaultMethod = null,
    ) {}

    // ---- Gateway -------------------------------------------------------------------------------

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
            method: $method ?? (string) $this->defaultMethod,
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
        $this->throwIfFailing();
        $this->created[] = $request;

        $existing = $this->orders[$request->ref] ?? null;

        if ($existing !== null && $existing['status'] === Order::STATUS_PAID) {
            return Order::fromArray($existing);
        }

        $now = now()->toIso8601String();
        $payload = $request->toArray();

        $this->orders[$request->ref] = $this->withPayment([
            'ref' => $request->ref,
            'pay_code' => $request->payCode ?? $request->ref,
            'method' => $request->method,
            'status' => Order::STATUS_OPEN,
            'amount_due' => $request->amount,
            'amount_paid' => (int) ($existing['amount_paid'] ?? 0),
            'currency' => 'VND',
            'customer_name' => $request->customerName,
            'description' => $request->description,
            'items' => $payload['items'] ?? [],
            'priority' => $request->priority ?? 100,
            'valid_from' => $request->validFrom,
            'expires_at' => $request->expiresAt,
            'metadata' => $request->metadata ?? [],
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ]);

        return Order::fromArray($this->orders[$request->ref]);
    }

    public function getOrder(string $ref): Order
    {
        $this->throwIfFailing();

        return Order::fromArray($this->find($ref));
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
        $this->throwIfFailing();

        $changes = OrderChanges::toArray(
            $amount, $method, $customerName, $description, $items, $payCode,
            $priority, $validFrom, $expiresAt, $customerEmail, $metadata,
        );

        $order = $this->find($ref);
        $this->guardEditable($order);
        $this->updated[] = ['ref' => $ref, 'changes' => $changes];

        if (isset($changes['amount'])) {
            $changes['amount_due'] = $changes['amount'];
            unset($changes['amount']);
        }

        unset($changes['customer_email']);
        $this->orders[$ref] = $this->withPayment(array_merge($order, $changes, ['updated_at' => now()->toIso8601String()]));

        return Order::fromArray($this->orders[$ref]);
    }

    public function cancelOrder(string $ref): Order
    {
        $this->throwIfFailing();

        $order = $this->find($ref);

        if ((int) $order['amount_paid'] > 0) {
            throw new ConflictException('Đơn đã có tiền vào.', 409, 'already_paid');
        }

        $this->cancelled[] = $ref;
        $this->orders[$ref]['status'] = Order::STATUS_CANCELLED;
        $this->orders[$ref]['updated_at'] = now()->toIso8601String();

        return Order::fromArray($this->orders[$ref]);
    }

    public function payments(int $cursor = 0, ?int $limit = null): PaymentPage
    {
        $this->throwIfFailing();

        $limit = max(1, min(500, $limit ?? 100));
        $after = array_values(array_filter($this->payments, fn (Payment $p) => $p->id > $cursor));
        $page = array_slice($after, 0, $limit);

        return new PaymentPage(
            $page,
            $page === [] ? $cursor : end($page)->id,
            count($after) > count($page),
        );
    }

    public function reconciliation(DateTimeInterface|string $date): Reconciliation
    {
        $this->throwIfFailing();

        return $this->reconciliation ?? new Reconciliation(Cast::dateOnly($date), [], []);
    }

    // ---- Điều khiển bản giả --------------------------------------------------------------------

    /**
     * Có tiền vào đơn $ref. Mặc định nộp đủ phần còn lại. Bắn PaymentReceived qua bảng chống trùng
     * và cho payment:pull thấy khoản này.
     *
     * @param  array<string, mixed>  $overrides  trường Payment muốn ghi đè (provider_transaction_id, paid_at…)
     */
    public function simulatePayment(string $ref, ?int $amount = null, array $overrides = []): Payment
    {
        $order = $this->orders[$ref] ?? null;
        $amount ??= $order !== null ? max(0, (int) $order['amount_due'] - (int) $order['amount_paid']) : null;

        if ($amount === null || $amount <= 0) {
            throw new InvalidArgumentException("simulatePayment('{$ref}'): cần amount > 0 (đơn chưa có hoặc đã thu đủ).");
        }

        $id = $this->nextPaymentId++;
        $payment = Payment::fromArray(array_merge([
            'id' => $id,
            'ref' => $ref,
            'pay_code' => $order['pay_code'] ?? $ref,
            'method' => $order['method'] ?? $this->defaultMethod ?? 'bidv',
            'amount' => $amount,
            'provider_transaction_id' => 'FAKE'.str_pad((string) $id, 10, '0', STR_PAD_LEFT),
            'paid_at' => now()->toIso8601String(),
            'payer_note' => $ref,
            'metadata' => $order['metadata'] ?? [],
        ], $overrides));

        if ($order !== null) {
            $paid = (int) $order['amount_paid'] + $payment->amount;
            $this->orders[$ref]['amount_paid'] = $paid;

            if ($paid >= (int) $order['amount_due']) {
                $this->orders[$ref]['status'] = Order::STATUS_PAID;
            }
        }

        $this->payments[] = $payment;
        $this->inbox->receivePayment($payment, 'fake', 'evt_fake_'.$this->nextEventId++);

        return $payment;
    }

    /**
     * Đơn $ref hết hạn chưa thu: bắn OrderExpired.
     */
    public function simulateExpired(string $ref): Order
    {
        $this->find($ref);
        $this->orders[$ref]['status'] = Order::STATUS_EXPIRED;
        $order = Order::fromArray($this->orders[$ref]);

        $this->inbox->receiveOrderExpired($order, 'fake', 'evt_fake_'.$this->nextEventId++);

        return $order;
    }

    /**
     * Lời gọi kế tiếp (bất kỳ) ném $e — vd. new ProviderUnavailableException(...).
     */
    public function failNext(Throwable $e): static
    {
        $this->failures[] = $e;

        return $this;
    }

    /**
     * Phương thức nào ra type=redirect (mặc định chỉ vnpay); còn lại là qr.
     */
    public function useRedirectFor(string ...$methods): static
    {
        $this->redirectMethods = array_values($methods);

        return $this;
    }

    public function setReconciliation(Reconciliation $reconciliation): static
    {
        $this->reconciliation = $reconciliation;

        return $this;
    }

    // ---- Khẳng định ----------------------------------------------------------------------------

    /**
     * @param  (callable(OrderRequest): bool)|string|null  $callback  callback, hoặc ref
     */
    public function assertOrderCreated(callable|string|null $callback = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->createdMatching($callback),
            'Không có lời gọi tạo đơn nào khớp điều kiện.',
        );
    }

    /**
     * @param  (callable(OrderRequest): bool)|string|null  $callback
     */
    public function assertOrderCreatedTimes(int $times, callable|string|null $callback = null): void
    {
        $count = count($this->createdMatching($callback));

        PHPUnit::assertSame($times, $count, "Mong {$times} lời gọi tạo đơn khớp điều kiện, thực tế {$count}.");
    }

    /**
     * @param  (callable(OrderRequest): bool)|string|null  $callback
     */
    public function assertOrderNotCreated(callable|string|null $callback = null): void
    {
        PHPUnit::assertEmpty($this->createdMatching($callback), 'Có lời gọi tạo đơn khớp điều kiện mà lẽ ra không được có.');
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $callback  nhận mảng trường đã sửa
     */
    public function assertOrderUpdated(string $ref, ?callable $callback = null): void
    {
        $matches = array_filter(
            $this->updated,
            fn (array $u) => $u['ref'] === $ref && ($callback === null || $callback($u['changes'])),
        );

        PHPUnit::assertNotEmpty($matches, "Đơn {$ref} không được sửa (khớp điều kiện).");
    }

    public function assertOrderCancelled(string $ref): void
    {
        PHPUnit::assertContains($ref, $this->cancelled, "Đơn {$ref} không bị huỷ.");
    }

    public function assertNothingSent(): void
    {
        PHPUnit::assertSame([], $this->created, 'Có đơn được tạo.');
        PHPUnit::assertSame([], $this->updated, 'Có đơn được sửa.');
        PHPUnit::assertSame([], $this->cancelled, 'Có đơn bị huỷ.');
    }

    /**
     * @return list<OrderRequest>
     */
    public function createdOrders(): array
    {
        return $this->created;
    }

    /**
     * @return array<string, Order>
     */
    public function orders(): array
    {
        return array_map(fn (array $o) => Order::fromArray($o), $this->orders);
    }

    // ---- Nội bộ --------------------------------------------------------------------------------

    /**
     * @return list<OrderRequest>
     */
    protected function createdMatching(callable|string|null $callback): array
    {
        return array_values(array_filter($this->created, function (OrderRequest $r) use ($callback) {
            return match (true) {
                $callback === null => true,
                is_string($callback) => $r->ref === $callback,
                default => (bool) $callback($r),
            };
        }));
    }

    /**
     * @return array<string, mixed>
     */
    protected function find(string $ref): array
    {
        return $this->orders[$ref] ?? throw new NotFoundException("Không có đơn {$ref}.", 404, 'order_not_found');
    }

    /**
     * @param  array<string, mixed>  $order
     */
    protected function guardEditable(array $order): void
    {
        if ((int) $order['amount_paid'] > 0) {
            throw new ConflictException('Đơn đã có tiền vào.', 409, 'already_paid');
        }

        if (in_array($order['status'], [Order::STATUS_CANCELLED, Order::STATUS_EXPIRED], true)) {
            throw new ConflictException('Đơn đã đóng.', 409, 'order_closed');
        }
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    protected function withPayment(array $order): array
    {
        $ref = $order['ref'];
        $redirect = in_array($order['method'], $this->redirectMethods, true);
        $order['type'] = $redirect ? Order::TYPE_REDIRECT : Order::TYPE_QR;
        $order['payment'] = [
            'bank_code' => $redirect ? null : strtoupper((string) $order['method']),
            'account_no' => $redirect ? null : 'FAKE'.$ref,
            'account_name' => $redirect ? null : 'TRUONG DAI HOC VIET NHAT',
            'qr_string' => $redirect ? null : 'FAKEQR|'.$ref.'|'.$order['amount_due'],
            'qr_image' => $redirect ? null : 'data:image/png;base64,iVBORw0KGgo=',
            'checkout_url' => $redirect ? 'https://payment-gateway.test/checkout/'.$ref : null,
            'issued_at' => now()->toIso8601String(),
        ];

        return $order;
    }

    protected function throwIfFailing(): void
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
    }
}
