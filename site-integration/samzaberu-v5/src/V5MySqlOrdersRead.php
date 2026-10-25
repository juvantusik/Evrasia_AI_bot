<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/** V5-only HTTP order history backed by the existing strong V5 session. */
final readonly class V5MySqlOrdersRead implements V5OrdersHttpReaderPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedOrdersReaderPort $orders,
    ) {}

    /** @return list<array<string,int|string|float|bool|null>>|null */
    public function readVerifiedOrders(#[\SensitiveParameter] string $accessJwt): ?array
    {
        return $this->sessions->readOrdersForAccess($accessJwt, $this->orders);
    }
}
