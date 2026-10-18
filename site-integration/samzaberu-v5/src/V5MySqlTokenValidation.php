<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5AuthHttpHandler.php';

/**
 * STAGING ONLY: validates V5 access JWT against the same authoritative,
 * locked user/session/epoch/revocation check as protected V5 operations.
 * A cryptographically valid but revoked token is NOT a valid login.
 *
 * This performs no refresh, PIN read, SMS or mutation. The actual website
 * /api/v5 router remains disabled until all native safety gates pass.
 */
final readonly class V5MySqlTokenValidation implements V5TokenValidationPort
{
    public function __construct(private V5MySqlStrongSessionStore $sessions) {}

    public function isValidAccessToken(#[\SensitiveParameter] string $accessJwt): bool
    {
        return $this->sessions->currentAssuranceSnapshot($accessJwt) !== null;
    }
}
