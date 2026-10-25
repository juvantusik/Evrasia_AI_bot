<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/**
 * V5-only profile handler. Reuses the V5 strong-session store and the
 * native Bitrix profile reader, not a V4 endpoint or V4 JWT.
 */
final readonly class V5MySqlProfileRead implements V5ProfileHttpReaderPort
{
    public function __construct(
        private V5MySqlStrongSessionStore $sessions,
        private V5LockedProfileReaderPort $profile,
    ) {}

    /** @return array<string,int|string|null>|null */
    public function readVerifiedProfile(#[\SensitiveParameter] string $accessJwt): ?array
    {
        return $this->sessions->readProfileForAccess($accessJwt, $this->profile);
    }
}
