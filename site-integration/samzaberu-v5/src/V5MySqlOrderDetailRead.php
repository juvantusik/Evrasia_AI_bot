<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/** Own V5 order detail port, never V4 HTTP or its session/JWT. */
final readonly class V5MySqlOrderDetailRead implements V5OrderDetailHttpReaderPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedOrderDetailReaderPort $details,
    ) {}

    /** @return array<string,mixed>|null */
    public function readVerifiedOrderDetail(
        #[\SensitiveParameter] string $accessJwt, int $orderId
    ): ?array {
        return $this->sessions->readOrderDetailForAccess($accessJwt, $orderId, $this->details);
    }
}
