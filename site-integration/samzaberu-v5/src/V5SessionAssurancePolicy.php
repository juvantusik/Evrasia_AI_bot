<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Second gate for V5-only operations such as bonus-PIN disclosure.
 *
 * Important: $verifiedClaims MUST come from an independently signature-
 * validated V5 access JWT; $serverSession MUST come from an authoritative
 * server-side session/assurance store with a successful revocation check.
 * This policy NEVER decodes tokens and MUST NOT be called with client claims.
 */
final class V5SessionAssurancePolicy
{
    public static function permitsProtectedAction(
        array $verifiedClaims,
        ?array $serverSession,
        int $now
    ): bool {
        if ($now <= 0 || $serverSession === null) {
            return false;
        }

        if (($verifiedClaims['iss'] ?? null) !== 'evrasia-samzaberu-v5'
            || ($verifiedClaims['aud'] ?? null) !== 'samzaberu-v5'
            || ($verifiedClaims['token_use'] ?? null) !== 'access'
            || ($verifiedClaims['version'] ?? null) !== 'v5'
        ) {
            return false;
        }

        $userId = $verifiedClaims['sub'] ?? null;
        $sessionId = $verifiedClaims['sid'] ?? null;
        $iat = $verifiedClaims['iat'] ?? null;
        $exp = $verifiedClaims['exp'] ?? null;
        $epoch = $verifiedClaims['auth_epoch'] ?? null;

        if (!is_string($userId)
            || !ctype_digit($userId)
            || $userId === '0'
            || $userId[0] === '0'
            || !is_string($sessionId)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sessionId) !== 1
            || !is_int($iat) || !is_int($exp) || !is_int($epoch)
            || $iat > $now + 30
            || $iat >= $exp || $exp <= $now
        ) {
            return false;
        }

        if (($serverSession['version'] ?? null) !== 'v5'
            || ($serverSession['status'] ?? null) !== 'active'
            || ($serverSession['revocation_verified'] ?? null) !== true
            || !is_string($serverSession['sid'] ?? null)
            || !hash_equals($serverSession['sid'], $sessionId)
            || !is_int($serverSession['user_id'] ?? null)
            || (string) $serverSession['user_id'] !== $userId
            || !is_int($serverSession['auth_epoch'] ?? null)
            || $serverSession['auth_epoch'] !== $epoch
        ) {
            return false;
        }

        $assurance = $serverSession['assurance'] ?? null;
        if (!is_string($assurance)
            || !in_array($assurance, ['totp', 'sms', 'email', 'trusted'], true)
        ) {
            return false;
        }

        $assuredAt = $serverSession['assured_at'] ?? null;
        $sessionExpiresAt = $serverSession['expires_at'] ?? null;
        if (!is_int($assuredAt)
            || !is_int($sessionExpiresAt)
            || $assuredAt <= 0
            || $assuredAt > $iat
            || $assuredAt > $now
            || $sessionExpiresAt <= $now
        ) {
            return false;
        }

        return true;
    }
}
