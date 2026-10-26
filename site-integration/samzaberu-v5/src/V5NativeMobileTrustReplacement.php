<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MobileTrustReplacementPolicy.php';
require_once __DIR__ . '/V5MySqlStrongSessionStore.php';

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Evrasia\Orm\DeviceAuthEventTable;
use Evrasia\Orm\TrustedDeviceTable;
use PDO;
use RuntimeException;

/**
 * V5-only real Bitrix ORM mutation, intended as a post-MFA operation inside
 * ONE V5 strong-session issuance transaction. NOT an HTTP handler/factory.
 *
 * Caller must:
 * (1) own a native Bitrix transaction with b_user locked;
 * (2) have successfully reverified the consumed TOTP/email/SMS grant against
 *     the same native connection + current AUTH_EPOCH;
 * (3) hold the existing user-wide ev_td_u_... advisory lock until AFTER commit;
 * (4) issue a V5 session using the same consumed grant and transaction.
 *
 * This class independently verifies condition (3) from MariaDB IS_USED_LOCK.
 * The transaction/strong-grant ownership is not attestable by this class,
 * hence only the private verified V5 factory may invoke it. No production
 * factory exists yet. Do NOT add a public route or call it from V4.
 */
final class V5NativeMobileTrustReplacement
{
    private const TTL_SECONDS = 90 * 86400;
    private const REVOKED = 2;
    private const ACTIVE = 1;
    private const SECURITY_REASON = 3;
    private const EVENT_CREATED = 10;
    private const EVENT_ROTATED = 11;
    private const EVENT_REVOKED = 12;

    /**
     * @return array{token_version:int,old_mobile_revoked:int}
     * @throws RuntimeException On any unverified native state or failed write.
     * @internal Must be called only from locked and covered V5 MFA issuance.
     */
    public function replaceInsideVerifiedMfaTransaction(
        PDO $db,
        ConsumedLoginChallenge $grant,
        V5LockedStrongSessionProofPort $strongGrantProof,
        #[\SensitiveParameter] string $clientGeneratedTrustToken,
    ): array {
        $verifiedUserId = $grant->userId;
        $platform = $grant->platform;
        $hash = V5MobileTrustReplacementPolicy::coreDeviceHash($grant->deviceId);
        if ($verifiedUserId < 1 || !in_array($grant->kind, [
                ChallengeKind::TOTP, ChallengeKind::SMS, ChallengeKind::EMAIL
            ], true)
            || !in_array($platform, ['ios','android'], true)
            || $hash === null
            || preg_match('/\A[a-f0-9]{64}\z/D', $clientGeneratedTrustToken) !== 1
            || !$db->inTransaction()
            || $strongGrantProof->verifiedConsumedGrantWithUserLock($db, $grant) !== true
            || !class_exists(Application::class)
            || !class_exists(TrustedDeviceTable::class)
            || !class_exists(DeviceAuthEventTable::class)) {
            throw new RuntimeException('v5_mobile_trust_enrollment_unavailable');
        }

        $native = Application::getConnection();
        if (!is_a($native, 'Bitrix\\Main\\DB\\MysqliConnection')
            || !method_exists($native, 'getResource')
            || !method_exists($native, 'query')
            || !method_exists($native, 'queryExecute')
            || !$this->userAdvisoryLockHeld($native, $verifiedUserId)) {
            throw new RuntimeException('v5_mobile_trust_native_scope_unverified');
        }

        // This is a single native Bitrix transaction and no standalone BEGIN.
        // V5 caller MUST retain the user lock through native COMMIT/ROLLBACK.
        $ua = 'SamZaberu platform=' . $platform;
        $now = new DateTime();
        $expires = DateTime::createFromTimestamp(time() + self::TTL_SECONDS);

        $rows = TrustedDeviceTable::getList([
            'select'=>['ID','USER_ID','DEVICE_ID_HASH','TOKEN_VERSION','STATUS','USER_AGENT'],
            'filter'=>['=USER_ID'=>$verifiedUserId],
            'order'=>['ID'=>'ASC'],
        ]);
        $current = null;
        $oldMobiles = [];
        while ($row = $rows->fetch()) {
            if (!is_array($row)
                || (int)($row['USER_ID'] ?? 0) !== $verifiedUserId
                || !is_string($row['DEVICE_ID_HASH'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D',$row['DEVICE_ID_HASH']) !== 1) {
                throw new RuntimeException('v5_mobile_trust_row_unverified');
            }
            if (hash_equals($row['DEVICE_ID_HASH'], $hash)) {
                if ($current !== null) {
                    throw new RuntimeException('v5_mobile_trust_duplicate_current_device');
                }
                $current = $row;
            } elseif (V5MobileTrustReplacementPolicy::replaceableOldMobileRow(
                $row, $verifiedUserId, $hash
            )) {
                $oldMobiles[] = $row;
            }
        }

        // An existing browser device ID with the same hash must NOT be
        // silently converted into a mobile device.
        if ($current !== null
            && !V5MobileTrustReplacementPolicy::isMobileUserAgent($current['USER_AGENT'] ?? null)) {
            throw new RuntimeException('v5_mobile_trust_namespace_collision');
        }

        $newVersion = $current === null ? 1 : ((int)$current['TOKEN_VERSION']) + 1;
        if ($newVersion < 1 || $newVersion > 2147483647) {
            throw new RuntimeException('v5_mobile_trust_token_version_overflow');
        }
        $fields = [
            'TRUST_TOKEN_HASH'=>hash('sha256', $clientGeneratedTrustToken),
            'STATUS'=>self::ACTIVE,
            'TOKEN_VERSION'=>$newVersion,
            'UPDATED_AT'=>$now,
            'LAST_SEEN_AT'=>$now,
            'TOKEN_EXPIRES_AT'=>$expires,
            'REVOKED_AT'=>null,
            'REVOKED_REASON'=>null,
            'USER_AGENT'=>$ua,
        ];
        if ($current !== null) {
            $currentId = (int)$current['ID'];
            $this->assertOrm(TrustedDeviceTable::update($currentId, $fields));
            $event = self::EVENT_ROTATED;
        } else {
            $fields['USER_ID'] = $verifiedUserId;
            $fields['DEVICE_ID_HASH'] = $hash;
            // Existing Bitrix enum: AUTH_PASSWORD=2, AUTH_SMS=3.
            // A TOTP/email grant followed a verified password; the native
            // MFA kind is separately recorded in the V5 session.
            $fields['CREATED_METHOD'] = 2;
            $fields['CREATED_AT'] = $now;
            $result = TrustedDeviceTable::add($fields);
            $this->assertOrm($result);
            $currentId = (int)$result->getId();
            if ($currentId < 1) {
                throw new RuntimeException('v5_mobile_trust_insert_unverified');
            }
            $event = self::EVENT_CREATED;
        }

        $revoked = 0;
        foreach ($oldMobiles as $old) {
            $version = (int)$old['TOKEN_VERSION'] + 1;
            if ($version < 1 || $version > 2147483647) {
                throw new RuntimeException('v5_mobile_trust_old_version_overflow');
            }
            $id = (int)$old['ID'];
            $this->assertOrm(TrustedDeviceTable::update($id, [
                'TRUST_TOKEN_HASH'=>null,
                'STATUS'=>self::REVOKED,
                'TOKEN_VERSION'=>$version,
                'UPDATED_AT'=>$now,
                'REVOKED_AT'=>$now,
                'REVOKED_REASON'=>self::SECURITY_REASON,
            ]));
            $this->audit($verifiedUserId, $old['DEVICE_ID_HASH'], $id,
                self::EVENT_REVOKED, $now, null);
            $revoked++;
        }

        $this->audit($verifiedUserId, $hash, $currentId, $event, $now, $ua);

        // All V5 tokens for this account are mobile-issued today. Revoke
        // them in the SAME transaction before the new session is inserted.
        // No V4 JWT or Bitrix web session is altered by this statement.
        if ($native->queryExecute(
            'UPDATE ev_v5_strong_sessions SET status = \'revoked\', '
            . 'updated_at = UNIX_TIMESTAMP() WHERE user_id = '
            . $verifiedUserId . " AND status = 'active'"
        ) === false) {
            throw new RuntimeException('v5_mobile_session_revocation_unconfirmed');
        }

        return [
            'token_version'=>$newVersion,
            'old_mobile_revoked'=>$revoked,
        ];
    }

    private function userAdvisoryLockHeld(object $native, int $userId): bool
    {
        $name='ev_td_u_'.substr(hash('sha256',(string)$userId),0,56);
        $resource=$native->getResource();
        if (!$resource instanceof \mysqli || $resource->thread_id < 1) return false;
        $safe=$native->getSqlHelper()->forSql($name);
        $row=$native->query(
            "SELECT IS_USED_LOCK('{$safe}') AS owner_id"
        )->fetch();
        return is_array($row)
            && (string)($row['owner_id'] ?? '') === (string)$resource->thread_id;
    }

    private function audit(
        int $userId, string $hash, int $id, int $event,
        DateTime $now, ?string $userAgent,
    ): void {
        $this->assertOrm(DeviceAuthEventTable::add([
            'USER_ID'=>$userId,
            'DEVICE_ID_HASH'=>$hash,
            'TRUSTED_DEVICE_ID'=>$id,
            'EVENT_TYPE'=>$event,
            'AUTH_METHOD'=>null,
            'CREATED_AT'=>$now,
            'IP'=>null,
            'USER_AGENT'=>$userAgent,
        ]));
    }

    private function assertOrm(object $result): void
    {
        if (!method_exists($result,'isSuccess') || $result->isSuccess() !== true) {
            throw new RuntimeException('v5_mobile_trust_native_write_unconfirmed');
        }
    }
}
