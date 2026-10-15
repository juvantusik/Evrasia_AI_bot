<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/**
 * Staging-only offline wiring for the two protected V5 JSON operations.
 * Native Bitrix/MySQL connection, proof coverage, and bonus-PIN reader
 * are mandatory dependencies and have NO production implementations.
 *
 * This is NOT a route installer. The site /api/v5/router.php still
 * returns HTTP 503, and this class performs no Bitrix bootstrap.
 */
final readonly class V5MySqlSessionActions implements V5SessionActionsPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedBonusPinReaderPort $pinReader,
    ) {}

    public function rotateRefresh(#[\SensitiveParameter] string $refreshToken): AuthenticatedV5Session
    {
        return $this->sessions->rotateRefresh($refreshToken);
    }

    public function readBonusPin(#[\SensitiveParameter] string $accessJwt): ?string
    {
        return $this->sessions->showBonusPinForAccess($accessJwt, $this->pinReader);
    }
}
