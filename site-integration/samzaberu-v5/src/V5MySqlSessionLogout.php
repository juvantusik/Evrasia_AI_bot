<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/**
 * Real V5 session logout adapter: revoke the exact presented V5 access JWT
 * under the store's existing user->epoch->session transaction and coverage
 * gates. No V4 token, caller-provided user ID, or client-created session.
 */
final readonly class V5MySqlSessionLogout implements V5SessionLogoutPort
{
    public function __construct(private V5MySqlStrongSessionStore $sessions) {}

    public function revoke(#[\SensitiveParameter] string $accessJwt): bool
    {
        return $this->sessions->revokeAccess($accessJwt);
    }
}
