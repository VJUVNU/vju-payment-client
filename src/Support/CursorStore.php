<?php

namespace Vju\PaymentClient\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Con trỏ của payment:pull (id Payment cuối đã xử lý).
 *
 * @internal
 */
class CursorStore
{
    public const TABLE = 'payment_client_cursors';

    public const PAYMENTS = 'payments';

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
    ) {}

    public function get(string $name = self::PAYMENTS): int
    {
        return (int) ($this->connection()->table(self::TABLE)->where('name', $name)->value('cursor') ?? 0);
    }

    /**
     * Chỉ tiến, không lùi — hai lần kéo chồng nhau không kéo con trỏ ngược lại.
     */
    public function advance(int $cursor, string $name = self::PAYMENTS): void
    {
        $this->ensure($name);

        $this->connection()->table(self::TABLE)
            ->where('name', $name)
            ->where('cursor', '<', $cursor)
            ->update(['cursor' => $cursor, 'updated_at' => now()]);
    }

    /**
     * Đặt thẳng (kể cả lùi) — dùng cho payment:pull --cursor.
     */
    public function put(int $cursor, string $name = self::PAYMENTS): void
    {
        $this->ensure($name);

        $this->connection()->table(self::TABLE)
            ->where('name', $name)
            ->update(['cursor' => max(0, $cursor), 'updated_at' => now()]);
    }

    protected function ensure(string $name): void
    {
        $this->connection()->table(self::TABLE)->insertOrIgnore([
            'name' => $name,
            'cursor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
