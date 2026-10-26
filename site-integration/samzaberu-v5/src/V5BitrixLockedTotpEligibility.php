<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Native TOTP challenge eligibility inside the existing strong V5 transaction.
 * The password-bound challenge carries the HMAC of current Bitrix password
 * hash and AUTH_EPOCH. This adapter re-checks that exact HMAC under b_user
 * FOR UPDATE and rejects a changed user/password/epoch or inactive TOTP.
 *
 * This adapter never verifies or consumes codes. The existing
 * V5BitrixLockedTotpFactor calls Bitrix Otp::verify($code, true) and verifies
 * that its replay cursor advanced in the same native SQL transaction.
 * No V4 controller/JWT, PHP user session or test credentials.
 */
final readonly class V5BitrixLockedTotpEligibility
    implements V5LockedChallengeNativeFactorPort
{
    public function __construct(
        private V5BitrixPdoCompatibilityBridge $connection,
        private V5SecurityEventVersions $versions,
    ) {}

    public function currentFactorEligibleWithLocks(
        PDO $db, int $userId, ChallengeKind $kind,
        string $challengeAuthEpoch, ?string $verifiedPhoneStamp,
    ): bool {
        if ($db !== $this->connection
            || PHP_SAPI !== 'fpm-fcgi'
            || !$db->inTransaction()
            || $userId < 1 || $userId > 2147483647
            || $kind !== ChallengeKind::TOTP
            || $verifiedPhoneStamp !== null
            || preg_match('/\A[a-f0-9]{64}\z/D', $challengeAuthEpoch) !== 1
            || !$this->connection->ownsCurrentNativeTransaction(
                \Bitrix\Main\Application::getConnection()
            )) {
            return false;
        }

        try {
            $user = self::one($db, 'SELECT ID,PASSWORD,ACTIVE,BLOCKED,PASSWORD_EXPIRED '
                . 'FROM b_user WHERE ID = ? FOR UPDATE', [$userId]);
            if ($user === null
                || (string)($user['ID'] ?? '') !== (string)$userId
                || ($user['ACTIVE'] ?? null) !== 'Y'
                || ($user['BLOCKED'] ?? null) !== 'N'
                || ($user['PASSWORD_EXPIRED'] ?? null) !== 'N') {
                return false;
            }

            // Same canonical domain-separated HMAC as TOTP issuance.
            $current = $this->versions->forLockedPasswordProof($db, $userId, $user);
            if (!is_string($current)
                || !hash_equals($current, $challengeAuthEpoch)) {
                return false;
            }

            $otp = self::one($db, 'SELECT USER_ID,ACTIVE,TYPE,SECRET,DEACTIVATE_UNTIL '
                . 'FROM b_sec_user WHERE USER_ID = ? FOR UPDATE', [$userId]);
            return $otp !== null
                && (string)($otp['USER_ID'] ?? '') === (string)$userId
                && ($otp['ACTIVE'] ?? null) === 'Y'
                && ($otp['TYPE'] ?? null) === 'totp'
                && $otp['DEACTIVATE_UNTIL'] === null
                && is_string($otp['SECRET'] ?? null)
                && preg_match('/\A[a-fA-F0-9]{40}\z/D', $otp['SECRET']) === 1;
        } catch (Throwable) {
            return false;
        }
    }

    public function consumeNativeTotpWithLocks(
        PDO $db, int $userId, #[\SensitiveParameter] string $code,
    ): bool {
        // Only the installed Bitrix native factor may consume TOTP.
        return false;
    }

    /** @return array<string,mixed>|null */
    private static function one(PDO $db, string $sql, array $params): ?array
    {
        $q = $db->prepare($sql);
        if (!$q instanceof PDOStatement || !$q->execute($params)) {
            return null;
        }
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && $q->fetch(PDO::FETCH_ASSOC) === false ? $row : null;
    }
}
