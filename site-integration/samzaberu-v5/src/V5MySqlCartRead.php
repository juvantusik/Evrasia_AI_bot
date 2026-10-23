<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/**
 * Real cart read composition: use the same V5 access-JWT, epoch, assurance,
 * user lock and server-side revocation checks as protected PIN reads.
 *
 * No client-supplied user ID or FUSER_ID, and no fallback to V4 JWT.
 */
final readonly class V5MySqlCartRead implements V5CartHttpReaderPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedCartReaderPort $cart,
    ) {}

    public function readVerifiedCart(#[\SensitiveParameter] string $accessJwt): ?array
    {
        return $this->sessions->readCartForAccess($accessJwt, $this->cart);
    }
}
