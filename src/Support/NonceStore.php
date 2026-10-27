<?php

namespace Vju\PaymentClient\Support;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * Nonce của webhook đã dùng. Giữ gấp đôi cửa sổ thời gian là đủ: nonce cũ hơn thế thì timestamp
 * đi kèm đã bị chặn ở bước kiểm cửa sổ.
 *
 * @internal
 */
class NonceStore
{
    public const TABLE = 'payment_client_nonces';

    public function __construct(
        protected ConnectionResolverInterface $db,
        protected ?string $connection = null,
    ) {}

    /**
     * @return bool false nếu nonce đã dùng (replay)
     */
    public function claim(string $sender, string $nonce, int $window): bool
    {
        $connection = $this->connection();

        $connection->table(self::TABLE)->where('used_at', '<', now()->subSeconds($window * 2))->delete();

        return $connection->table(self::TABLE)->insertOrIgnore([
            'sender' => $sender,
            'nonce' => $nonce,
            'used_at' => now(),
        ]) === 1;
    }

    protected function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }
}
