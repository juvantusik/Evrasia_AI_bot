<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * V5 mobile-only replacement scope.
 *
 * The existing mobile adapter writes USER_AGENT as
 * "SamZaberu platform=ios[; ...]" or "SamZaberu platform=android[; ...]".
 * The legacy exclusive writer historically compared that field against
 * "SAMZABERU_" (different bytes), which cannot identify these rows.
 *
 * This class only decides row scope; it neither changes V4 registrations
 * nor writes shared Trusted Device rows. Actual replacement must be in an
 * independently verified V5-owned transaction after strong MFA, with the
 * same user-wide advisory locking protocol as TrustedDeviceService.
 */
final class V5MobileTrustReplacementPolicy
{
    /** Match the exact stored mobile USER_AGENT namespace, not arbitrary substrings. */
    public static function isMobileUserAgent(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\ASamZaberu platform=(?:ios|android)(?:; [^\r\n]*)?\z/D',
            $value
        ) === 1;
    }

    public static function isBrowserOrUnknown(mixed $value): bool
    {
        return !self::isMobileUserAgent($value);
    }

    /**
     * Decision is only for rows ALREADY selected and locked for the same
     * server-verified USER_ID. Never select by client-provided USER_ID.
     *
     * @param array<string,mixed> $lockedRow
     */
    public static function replaceableOldMobileRow(
        array $lockedRow, int $verifiedUserId, string $newDeviceHash,
    ): bool {
        if ($verifiedUserId < 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $newDeviceHash) !== 1
            || !self::isMobileUserAgent($lockedRow['USER_AGENT'] ?? null)
            || !self::positiveEquals($lockedRow['USER_ID'] ?? null, $verifiedUserId)
            || !self::positiveEquals($lockedRow['STATUS'] ?? null, 1)
            || !is_string($lockedRow['DEVICE_ID_HASH'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $lockedRow['DEVICE_ID_HASH']) !== 1
        ) {
            return false;
        }

        // The current installation rotates its own token. Only OTHER
        // mobile installation hashes are candidates for revocation.
        return !hash_equals($newDeviceHash, $lockedRow['DEVICE_ID_HASH']);
    }

    public static function coreDeviceHash(string $externalDeviceId): ?string
    {
        return preg_match('/\Asz_[a-f0-9]{64}\z/D', $externalDeviceId) === 1
            ? hash('sha256', hash('sha256', $externalDeviceId))
            : null;
    }

    private static function positiveEquals(mixed $value, int $expected): bool
    {
        if ($expected < 1 || (!is_int($value) && !is_string($value))) return false;
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1)
            return false;
        return filter_var($value, FILTER_VALIDATE_INT, [
            'options'=>['min_range'=>1],
        ]) === $expected;
    }
}
