<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * V5-only binding between a committed trusted-device session and the exact
 * version of the current mobile trust record. This is NOT a trust verifier:
 * callers MUST own the same native transaction and use SELECT ... FOR UPDATE.
 * No original device ID, plaintext token or token hash is persisted in V5.
 */
final class V5TrustedSessionBinding
{
    private const DOMAIN = "evrasia:v5:mobile-trust-session:v1\0";

    public static function presented(
        string $deviceId, string $platform,
        #[\SensitiveParameter] string $token,
        array $lockedTrustRow, int $userId, int $now,
        #[\SensitiveParameter] string $key,
    ): ?string {
        if (preg_match('/\Asz_[a-f0-9]{64}\z/D', $deviceId) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return null;
        }
        $deviceHash = hash('sha256', hash('sha256', $deviceId));
        $tokenHash = hash('sha256', $token);
        if (!is_string($lockedTrustRow['DEVICE_ID_HASH'] ?? null)
            || !is_string($lockedTrustRow['TRUST_TOKEN_HASH'] ?? null)
            || !hash_equals($deviceHash, $lockedTrustRow['DEVICE_ID_HASH'])
            || !hash_equals($tokenHash, $lockedTrustRow['TRUST_TOKEN_HASH'])) {
            return null;
        }
        return self::current($lockedTrustRow, $userId, $now, $platform, $key);
    }

    /** Input must originate from a current, row-locked DB record, not JSON. */
    public static function current(
        array $lockedTrustRow, int $userId, int $now,
        string $platform, #[\SensitiveParameter] string $key,
    ): ?string {
        if ($userId < 1 || $now < 1 || strlen($key) < 32
            || !in_array($platform, ['ios', 'android'], true)
            || !self::numericEquals($lockedTrustRow['USER_ID'] ?? null, $userId)
            || !self::numericEquals($lockedTrustRow['STATUS'] ?? null, 1)) {
            return null;
        }
        $deviceHash = $lockedTrustRow['DEVICE_ID_HASH'] ?? null;
        $tokenHash = $lockedTrustRow['TRUST_TOKEN_HASH'] ?? null;
        $version = self::positive($lockedTrustRow['TOKEN_VERSION'] ?? null);
        $expires = self::positive($lockedTrustRow['expires_epoch'] ?? null);
        $ua = $lockedTrustRow['USER_AGENT'] ?? null;
        $prefix = 'SamZaberu platform=' . $platform;
        if (!is_string($deviceHash) || !is_string($tokenHash)
            || preg_match('/\A[a-f0-9]{64}\z/D', $deviceHash) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $tokenHash) !== 1
            || $version === null || $expires === null || $expires <= $now
            || !is_string($ua)
            || !($ua === $prefix || str_starts_with($ua, $prefix . '; '))) {
            return null;
        }
        return hash_hmac('sha256', self::DOMAIN . $deviceHash . "\0"
            . $platform . "\0" . $tokenHash . "\0" . $version, $key);
    }

    private static function positive(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)
            return null;
        $v = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        return $v === false ? null : $v;
    }

    private static function numericEquals(mixed $value, int $expected): bool
    {
        return self::positive($value) === $expected;
    }
}
