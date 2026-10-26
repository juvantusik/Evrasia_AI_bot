<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use Bitrix\Main\Application;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Real V5 mobile-trust proof, evaluated AGAIN under the strong-session
 * transaction's existing b_user lock. The first-factor proof has already
 * resolved the user; this adapter verifies the current password hash again.
 *
 * The private HTTP factory must load the V5 interfaces/classes from ONE
 * coherent tree before requiring this file. In particular, do not load both
 * Controllers/V5/Runtime and local/samzaberu-v5/src duplicates.
 *
 * This implementation never generates, returns, registers or renews a mobile
 * trust token. It does not use V4 JWT, CUser::Login(), sessions or cookies.
 * The owning V5MySqlStrongSessionStore separately locks and validates the
 * same trust row, and enforces native coverage before issuing any JWT.
 */
final readonly class V5BitrixLockedMobileTrustProof implements V5LockedStrongSessionProofPort
{
    public function __construct(
        private V5BitrixPdoCompatibilityBridge $connection,
        private V5PasswordHashComparatorPort $passwords,
        #[\SensitiveParameter] private ?string $enteredPassword,
        #[\SensitiveParameter] private string $bindingKey,
    ) {
        if (($enteredPassword !== null && ($enteredPassword === '' || strlen($enteredPassword) > 4096))
            || strlen($bindingKey) < 32 || strlen($bindingKey) > 1024) {
            throw new \InvalidArgumentException('v5_mobile_trust_proof_configuration_unavailable');
        }
    }

    public function verifiedTrustWithUserLock(
        PDO $db,
        PasswordVerifiedAccount $account,
        string $deviceId,
        string $platform,
        #[\SensitiveParameter] string $trustToken,
    ): bool {
        if ($db !== $this->connection
            || PHP_SAPI !== 'fpm-fcgi'
            || !$db->inTransaction()
            || $this->enteredPassword === null
            || !$account->accountAllowed
            || $account->userId < 1
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || preg_match('/\A[a-f0-9]{64}\z/D', $trustToken) !== 1) {
            return false;
        }

        try {
            $native = Application::getConnection();
            if (!$this->connection->ownsCurrentNativeTransaction($native)) {
                return false;
            }

            // Session issuance already acquired b_user FOR UPDATE. Re-read
            // under that lock, so password rotation / block cannot reuse a
            // stale first-factor snapshot.
            $user = self::one($db, 'SELECT PASSWORD, ACTIVE, BLOCKED, PASSWORD_EXPIRED '
                . 'FROM b_user WHERE ID = ? FOR UPDATE', [$account->userId]);
            if ($user === null
                || ($user['ACTIVE'] ?? null) !== 'Y'
                || ($user['BLOCKED'] ?? null) !== 'N'
                || ($user['PASSWORD_EXPIRED'] ?? null) !== 'N'
                || !is_string($user['PASSWORD'] ?? null)
                || !$this->passwords->matches($user['PASSWORD'], $this->enteredPassword)) {
                return false;
            }

            // Match the installed mobile-trust record format already used by
            // V5MySqlStrongSessionStore. No client-supplied user identity,
            // SQL interpolation or untrusted evidence flags.
            $deviceHash = hash('sha256', hash('sha256', $deviceId));
            $trust = self::one($db,
                'SELECT USER_ID, DEVICE_ID_HASH, TRUST_TOKEN_HASH, '
                . 'TOKEN_VERSION, STATUS, '
                . 'UNIX_TIMESTAMP(TOKEN_EXPIRES_AT) AS expires_epoch, USER_AGENT '
                . 'FROM ev_trusted_devices WHERE USER_ID = ? '
                . 'AND DEVICE_ID_HASH = ? FOR UPDATE',
                [$account->userId, $deviceHash]
            );
            if ($trust === null) {
                return false;
            }

            $nowRow = self::one($db, 'SELECT UNIX_TIMESTAMP() AS ts', []);
            $rawNow = $nowRow['ts'] ?? null;
            if ((!is_string($rawNow) && !is_int($rawNow))
                || preg_match('/\A[1-9][0-9]{0,10}\z/D', (string)$rawNow) !== 1) {
                return false;
            }

            // The shared binding additionally checks the correct user,
            // token hash, version, expiry, platform, status and user agent.
            return V5TrustedSessionBinding::presented(
                $deviceId, $platform, $trustToken, $trust,
                $account->userId, (int)$rawNow, $this->bindingKey
            ) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    public function verifiedConsumedGrantWithUserLock(
        PDO $db,
        ConsumedLoginChallenge $grant,
    ): bool {
        // Consumed TOTP/email/SMS grants belong only to the existing
        // V5MySqlConsumedChallengeProof, never to this trust adapter.
        return false;
    }

    /** @return array<string,mixed>|null */
    private static function one(PDO $db, string $sql, array $params): ?array
    {
        $statement = $db->prepare($sql);
        if (!$statement instanceof PDOStatement || !$statement->execute($params)) {
            return null;
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $statement->fetch(PDO::FETCH_ASSOC) !== false) {
            return null;
        }
        return $row;
    }
}
