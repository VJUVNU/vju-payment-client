<?php

namespace Vju\PaymentClient\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Data\Payment;
use Vju\PaymentClient\Events\OrderExpired;
use Vju\PaymentClient\Events\PaymentReceived;

/**
 * Cửa duy nhất để sự kiện của cổng thành event Laravel — webhook, payment:pull và fake đều đi qua
 * đây, nên không đường nào bắn trùng đường nào.
 *
 * Ngữ nghĩa: ghi dòng chống trùng (chỉ mục duy nhất, trùng thì bỏ qua im lặng) và dispatch event
 * trong CÙNG một transaction. Listener đồng bộ ném lỗi → transaction huỷ → dòng chống trùng mất →
 * lần giao sau (cổng gửi lại webhook, hoặc payment:pull) bắn lại. Listener đồng bộ ghi CSDL trên cùng
 * kết nối thì việc ghi của nó và dòng chống trùng cùng thành hoặc cùng mất.
 *
 * Hai lượt giao cùng lúc: lượt sau chờ khoá của chỉ mục duy nhất tới khi lượt trước commit rồi bỏ
 * qua (MySQL/PostgreSQL); lượt trước rollback thì lượt sau được ghi và bắn.
 *
 * @internal
 */
class Inbox
{
    public const TABLE = 'payment_client_events';

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
        protected ?Dispatcher $events = null,
    ) {}

    /**
     * @return bool true nếu đã bắn PaymentReceived, false nếu trùng
     */
    public function receivePayment(Payment $payment, string $source, ?string $eventId = null): bool
    {
        return $this->record(
            [
                'dedupe_key' => self::paymentKey($payment->id),
                'kind' => 'payment.paid',
                'gateway_payment_id' => $payment->id,
                'event_id' => $eventId,
                'ref' => $payment->ref,
                'source' => $source,
                'payload' => $payment->raw,
            ],
            fn () => $this->events()->dispatch(new PaymentReceived($payment, $source, $eventId)),
        );
    }

    /**
     * @return bool true nếu đã bắn OrderExpired, false nếu trùng
     */
    public function receiveOrderExpired(Order $order, string $source, ?string $eventId = null): bool
    {
        // Order không có id; id sự kiện là thứ ổn định qua các lần cổng gửi lại.
        $key = $eventId !== null && $eventId !== ''
            ? 'event:'.$eventId
            : 'order.expired:'.$order->ref.':'.($order->expiresAt?->format('Y-m-d\TH:i:sP') ?? '');

        return $this->record(
            [
                'dedupe_key' => $key,
                'kind' => 'order.expired',
                'gateway_payment_id' => null,
                'event_id' => $eventId,
                'ref' => $order->ref,
                'source' => $source,
                'payload' => $order->raw,
            ],
            fn () => $this->events()->dispatch(new OrderExpired($order, $source, $eventId)),
        );
    }

    public function hasPayment(int $paymentId): bool
    {
        return $this->connection()->table(self::TABLE)->where('dedupe_key', self::paymentKey($paymentId))->exists();
    }

    public static function paymentKey(int $paymentId): string
    {
        return 'payment:'.$paymentId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function record(array $row, Closure $dispatch): bool
    {
        $connection = $this->connection();
        $now = now();

        $row['payload'] = json_encode($row['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $row['processed_at'] = $now;
        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $connection->transaction(function () use ($connection, $row, $dispatch): bool {
            if ($connection->table(self::TABLE)->insertOrIgnore($row) === 0) {
                return false;
            }

            $dispatch();

            return true;
        });
    }

    /**
     * Lấy dispatcher từ container lúc bắn để Event::fake() gọi muộn vẫn ăn.
     */
    protected function events(): Dispatcher
    {
        return $this->events ?? app('events');
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
