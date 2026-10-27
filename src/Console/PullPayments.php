<?php

namespace Vju\PaymentClient\Console;

use Illuminate\Console\Command;
use Throwable;
use Vju\PaymentClient\Contracts\Gateway;
use Vju\PaymentClient\Exceptions\GatewayException;
use Vju\PaymentClient\Support\CursorStore;
use Vju\PaymentClient\Support\Inbox;

/**
 * Đường dự phòng của webhook (hợp đồng §4): kéo /payments từ con trỏ đã lưu, bắn PaymentReceived cho
 * id chưa thấy, rồi tiến con trỏ. Dùng chung bảng chống trùng với webhook nên hai đường không bắn trùng.
 *
 * Listener ném lỗi ở một khoản: con trỏ dừng ngay trước khoản đó, lệnh thoát mã lỗi; lần chạy sau
 * bắt đầu lại đúng từ khoản ấy.
 */
class PullPayments extends Command
{
    protected $signature = 'payment:pull
        {--limit= : Số khoản mỗi trang (1–500, mặc định theo cấu hình)}
        {--max-pages= : Số trang tối đa cho một lần chạy}
        {--cursor= : Đặt lại con trỏ về id này trước khi kéo (kể cả lùi)}';

    protected $description = 'Kéo kết quả thu từ Cổng thanh toán (dự phòng cho webhook)';

    public function handle(Gateway $gateway, Inbox $inbox, CursorStore $cursors): int
    {
        if ($this->option('cursor') !== null) {
            $cursors->put((int) $this->option('cursor'));
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : (int) config('payment-gateway.pull.limit', 100);
        $maxPages = max(1, $this->option('max-pages') !== null ? (int) $this->option('max-pages') : (int) config('payment-gateway.pull.max_pages', 20));

        $cursor = $cursors->get();
        $fired = 0;
        $skipped = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $result = $gateway->payments($cursor, $limit);
            } catch (GatewayException $e) {
                $this->error("Không kéo được /payments (con trỏ {$cursor}): ".$e->getMessage());

                return self::FAILURE;
            }

            foreach ($result->data as $payment) {
                try {
                    $inbox->receivePayment($payment, 'pull') ? $fired++ : $skipped++;
                } catch (Throwable $e) {
                    report($e);
                    $cursors->advance($cursor);
                    $this->error("Xử lý giao dịch {$payment->id} thất bại: ".$e->getMessage());
                    $this->line("Đã bắn {$fired}, bỏ qua {$skipped} trùng. Con trỏ dừng ở {$cursor}.");

                    return self::FAILURE;
                }

                $cursor = max($cursor, $payment->id);
            }

            $cursor = max($cursor, $result->nextCursor);
            $cursors->advance($cursor);

            // Không tiến được thì dừng, kể cả khi has_more — tránh quay vòng mãi.
            if (! $result->hasMore || $result->data === []) {
                break;
            }
        }

        $this->info("Đã bắn {$fired} giao dịch mới, bỏ qua {$skipped} trùng. Con trỏ: {$cursor}.");

        return self::SUCCESS;
    }
}
