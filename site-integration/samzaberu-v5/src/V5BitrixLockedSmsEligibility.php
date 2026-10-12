<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlSmsOutbox.php';
require_once __DIR__ . '/V5ExistingLoginPolicy.php';
require_once __DIR__ . '/V5NativeTotpStatus.php';

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Verified-phone provenance is NOT the mere value of PHONE_NUMBER/CONFIRMED.
 * This port must read a durable verification EVENT VERSION, on the supplied
 * PDO, and change when the same number is reconfirmed after a reset.
 * No site implementation is asserted or installed by this file.
 */
interface V5SmsPhoneVerificationVersionPort
{
    /** @param array<string,mixed> $lockedOrFreshPhone */
    public function stampFor(PDO $db, int $userId, array $lockedOrFreshPhone): ?string;
}

/**
 * Challenge auth_epoch must originate from password proof and be invalidated
 * by password reset, MFA enroll/reset, logout/block and account security events.
 * This provider MUST establish the same current epoch under the locked PDO;
 * an account-password hash alone is insufficient event provenance.
 */
interface V5SmsLockedAuthEpochPort
{
    /** @param array<string,mixed> $lockedUser */
    public function stillCurrent(PDO $db, int $userId, array $lockedUser, string $challengeEpoch): ?bool;
}

/** Site-owned canonicalization; MUST NOT silently change the recipient. */
interface V5SmsPhoneE164Port
{
    public function canonicalE164(string $bitrixPhone): ?string;
}

/** Safe default: accept only an already canonical E.164 value. */
final class V5StrictE164Phone implements V5SmsPhoneE164Port
{
    public function canonicalE164(string $bitrixPhone): ?string
    {
        return preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $bitrixPhone) === 1
            ? $bitrixPhone : null;
    }
}

/**
 * SELECT-only helper shared by preflight lookup and the locked SMS decision.
 * Exactly one authoritative b_user_phone_auth row is required. Do not fall
 * back to PERSONAL_PHONE, an SMS request field, or an unconfirmed number.
 */
final class V5BitrixSmsPhoneRows
{
    /** @return array<string,mixed>|null */
    public static function read(PDO $db, int $userId, bool $lock): ?array
    {
        if ($userId < 1 || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || ($lock && !$db->inTransaction())
        ) {
            throw new RuntimeException('verified_phone_connection_unavailable');
        }
        $sql = 'SELECT USER_ID, PHONE_NUMBER, CONFIRMED FROM b_user_phone_auth '
            . 'WHERE USER_ID = ? LIMIT 2' . ($lock ? ' FOR UPDATE' : '');
        $query = $db->prepare($sql);
        if (!$query instanceof PDOStatement || !$query->execute([$userId])) {
            throw new RuntimeException('verified_phone_store_unavailable');
        }
        $first = $query->fetch(PDO::FETCH_ASSOC);
        if ($first === false) {
            return null;
        }
        if (!is_array($first) || $query->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('verified_phone_ambiguous');
        }
        return $first;
    }

    public static function matchesUserId(mixed $candidate, int $userId): bool
    {
        return (is_int($candidate) && $candidate === $userId)
            || (is_string($candidate) && $candidate === (string)$userId);
    }

    public static function confirmedDestination(
        PDO $db,
        int $userId,
        ?array $row,
        V5SmsPhoneE164Port $normalizer,
        V5SmsPhoneVerificationVersionPort $version,
    ): ?V5VerifiedSmsDestination {
        if ($row === null || !self::matchesUserId($row['USER_ID'] ?? null, $userId)
            || ($row['CONFIRMED'] ?? null) !== 'Y'
            || !is_string($row['PHONE_NUMBER'] ?? null)
        ) {
            return null;
        }
        $phone = $normalizer->canonicalE164($row['PHONE_NUMBER']);
        if (!is_string($phone)
            || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $phone) !== 1
        ) {
            return null;
        }
        $stamp = $version->stampFor($db, $userId, $row);
        if (!is_string($stamp) || preg_match('/\A[a-f0-9]{64}\z/D', $stamp) !== 1) {
            return null;
        }
        return new V5VerifiedSmsDestination($userId, $phone, $stamp);
    }
}

/** Server-side preflight: the locked check MUST repeat all these facts. */
final readonly class V5BitrixVerifiedSmsPhone implements V5VerifiedSmsDestinationPort
{
    public function __construct(
        private PDO $db,
        private V5SmsPhoneE164Port $normalizer,
        private V5SmsPhoneVerificationVersionPort $version,
    ) {}

    public function forUser(int $userId): ?V5VerifiedSmsDestination
    {
        if ($userId < 1) {
            return null;
        }
        $row = V5BitrixSmsPhoneRows::read($this->db, $userId, false);
        return V5BitrixSmsPhoneRows::confirmedDestination(
            $this->db, $userId, $row, $this->normalizer, $this->version,
        );
    }
}

/**
 * Staging-only, REAL b_user -> b_sec_user -> b_user_phone_auth locking SQL.
 * Does not make a connection or read any Bitrix OTP secret. Any MFA row,
 * even inactive/disabled/unsupported, prevents SMS downgrade.
 *
 * Requires independently verified native login/mandatory MFA decisions,
 * durable phone verification event version and shared auth epoch. No
 * permissive default provider is provided. All must deny unknown state.
 */
final readonly class V5BitrixLockedSmsEligibility implements V5SmsLockedEligibilityPort
{
    public function __construct(
        private V5ExistingLoginPolicyPort $login,
        private V5NoMfaFallbackPolicyPort $fallback,
        private V5SmsPhoneE164Port $normalizer,
        private V5SmsPhoneVerificationVersionPort $phoneVersion,
        private V5SmsLockedAuthEpochPort $epoch,
    ) {}

    public function stillEligibleWithLocks(
        PDO $db,
        V5SmsQueueReservation $request,
        string $challengeAuthEpoch,
    ): bool {
        $userId = $request->userId;
        if ($userId < 1 || $request->destination->userId !== $userId
            || !$db->inTransaction()
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || preg_match('/\A[a-f0-9]{64}\z/D', $challengeAuthEpoch) !== 1
        ) {
            return false;
        }

        // Identical lock order to the recorded V3C reader; do not acquire
        // another user's mutex or a challenge lock inside these callbacks.
        $user = self::oneLocked($db,
            'SELECT ID, LOGIN, PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED, '
            . 'LOGIN_ATTEMPTS FROM b_user WHERE ID = ? LIMIT 2 FOR UPDATE', $userId);
        if ($user === null || !V5BitrixSmsPhoneRows::matchesUserId($user['ID'] ?? null, $userId)
            || ($user['ACTIVE'] ?? null) !== 'Y'
            || ($user['BLOCKED'] ?? null) !== 'N'
            || ($user['PASSWORD_EXPIRED'] ?? null) !== 'N'
            || !is_string($user['PASSWORD'] ?? null) || $user['PASSWORD'] === ''
        ) {
            return false;
        }
        $attempts = self::nonNegativeInt($user['LOGIN_ATTEMPTS'] ?? null);
        if ($attempts === null) {
            return false;
        }

        // No secret needed: mere presence of any configured MFA row means
        // the SMS branch is not a valid factor-selection fallback.
        $otp = self::oneLocked($db,
            'SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? LIMIT 2 FOR UPDATE', $userId);
        if ($otp !== null) {
            return false;
        }

        $phoneRow = V5BitrixSmsPhoneRows::read($db, $userId, true);
        $verified = V5BitrixSmsPhoneRows::confirmedDestination(
            $db, $userId, $phoneRow, $this->normalizer, $this->phoneVersion,
        );
        if ($verified === null
            || !hash_equals($verified->e164, $request->destination->e164)
            || !hash_equals($verified->verificationStamp, $request->destination->verificationStamp)
        ) {
            return false;
        }

        // These policy implementations remain injected / fail-closed until
        // real side-effect-free Bitrix providers are verified in staging.
        if ($this->login->permits($userId, $attempts) !== true
            || $this->fallback->permitsPasswordPlusSms($userId) !== true
        ) {
            return false;
        }
        return $this->epoch->stillCurrent($db, $userId, $user, $challengeAuthEpoch) === true;
    }

    /** @return array<string,mixed>|null */
    private static function oneLocked(PDO $db, string $sql, int $userId): ?array
    {
        $query = $db->prepare($sql);
        if (!$query instanceof PDOStatement || !$query->execute([$userId])) {
            throw new RuntimeException('bitrix_locked_identity_unavailable');
        }
        $first = $query->fetch(PDO::FETCH_ASSOC);
        if ($first === false) {
            return null;
        }
        if (!is_array($first) || $query->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('bitrix_locked_identity_ambiguous');
        }
        return $first;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $parsed === false ? null : $parsed;
    }
}
