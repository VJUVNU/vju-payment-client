<?php

namespace Vju\PaymentClient\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Vju\PaymentClient\Data\Order;
use Vju\PaymentClient\Data\Payment;
use Vju\PaymentClient\Exceptions\InvalidPayloadException;
use Vju\PaymentClient\Support\Inbox;

/**
 * Nhận webhook đã qua VerifyWebhookSignature.
 *
 * 200 = đã xử lý hoặc đã xử lý từ trước (trùng) hoặc sự kiện lạ (bỏ qua để cổng không gửi lại mãi).
 * 422 = chữ ký đúng nhưng thân hỏng. 500 = listener ném lỗi; dòng chống trùng đã rollback nên lần
 * cổng gửi lại (hoặc payment:pull) sẽ bắn lại.
 */
class WebhookController
{
    public function __invoke(Request $request, Inbox $inbox): JsonResponse
    {
        $envelope = json_decode((string) $request->getContent(), true);

        if (! is_array($envelope) || ! is_string($envelope['event'] ?? null) || ! is_array($envelope['data'] ?? null)) {
            return VerifyWebhookSignature::error('invalid_payload', 'Thân webhook phải có "event" và "data".', 422);
        }

        $event = $envelope['event'];
        $eventId = is_scalar($envelope['id'] ?? null) ? (string) $envelope['id'] : null;

        try {
            $fired = match ($event) {
                'payment.paid' => $inbox->receivePayment(Payment::fromArray($envelope['data']), 'webhook', $eventId),
                'order.expired' => $inbox->receiveOrderExpired(Order::fromArray($envelope['data']), 'webhook', $eventId),
                default => null,
            };
        } catch (InvalidPayloadException $e) {
            return VerifyWebhookSignature::error('invalid_payload', $e->getMessage(), 422);
        } catch (Throwable $e) {
            report($e);

            return VerifyWebhookSignature::error('listener_failed', 'Xử lý sự kiện thất bại; cổng sẽ gửi lại.', 500);
        }

        if ($fired === null) {
            Log::info('vju/payment-client: bỏ qua webhook có event lạ', ['event' => $event, 'id' => $eventId]);

            return new JsonResponse(['status' => 'ignored']);
        }

        return new JsonResponse(['status' => $fired ? 'processed' : 'duplicate']);
    }
}
