<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixLockedSmsEligibility.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Explicit proof that the site's native password/MFA/phone/logout/block
 * hooks and the shared b_user mutex are INSTALLED and fully cover this user.
 * No production implementation of this port exists yet. Unknown => deny.
 */
interface V5NativeSecurityHookCoveragePort
{
    public function fullyCovered(PDO $db, int $userId): ?bool;
}

/**
 * STAGING CANDIDATE ONLY. The shared ev_samzaberu_auth_state table
 * was created EMPTY on production on 2026-10-15. No per-user AUTH_EPOCH
 * initialization, native writer hooks or V5 activation has been deployed.
 * This uses the SAME V3C/V5 auth-epoch authority, not a second epoch table.
 *
 * All native Bitrix password/MFA/block/logout/phone-confirmation writers
 * MUST synchronize on b_user FOR UPDATE and record the security event in
 * the SAME native DB transaction. This integration is NOT implemented.
 * A missing/untrusted state row must never enable an SMS challenge.
 */
final readonly class V5SecurityEventVersions implements
    V5SmsPhoneVerificationVersionPort,
    V5SmsLockedAuthEpochPort
{
    public function __construct(
        private V5SmsPhoneE164Port $phones,
        private V5NativeSecurityHookCoveragePort $coverage,
        #[\SensitiveParameter] private string $phoneKey,
        #[\SensitiveParameter] private string $authKey,
    ) {
        if (strlen($phoneKey) < 32 || strlen($authKey) < 32 || hash_equals($phoneKey, $authKey)) {
            throw new InvalidArgumentException('Security event keys unavailable');
        }
    }

    /**
     * An HMAC of identity + phone (not of a static phone alone): used to
     * detect an unrecorded phone CHANGE. It cannot detect a missed event
     * reconfirming the SAME number: native event hooks remain mandatory.
     */
    public function phoneBinding(int $userId, string $e164): string
    {
        $canonical = $this->phones->canonicalE164($e164);
        if ($userId < 1 || $canonical === null || !hash_equals($e164, $canonical)) {
            throw new InvalidArgumentException('Canonical verified phone required');
        }
        return hash_hmac('sha256', "evrasia:v5:verified-phone-binding:v1\0"
            . $userId . "\0" . $e164, $this->phoneKey);
    }

    /**
     * Preflight uses a plain read; within SMS-outbox the same method is
     * called after b_user -> b_sec_user -> b_user_phone_auth locks, so
     * the shared state row is then locked LAST with FOR UPDATE.
     */
    public function stampFor(PDO $db, int $userId, array $lockedOrFreshPhone): ?string
    {
        $this->requireConnection($db, false);
        if ($userId < 1 || $this->coverage->fullyCovered($db, $userId) !== true) {
            return null;
        }
        $phone = $this->confirmedPhone($userId, $lockedOrFreshPhone);
        if ($phone === null) {
            return null;
        }
        $state = $this->readState($db, $userId, $db->inTransaction());
        if ($state === null || $state['phone_event_version'] < 1
            || $state['phone_binding_hmac'] === null
            || !hash_equals($state['phone_binding_hmac'], $this->phoneBinding($userId, $phone))
        ) {
            return null;
        }
        return hash_hmac('sha256', "evrasia:v5:phone-event-stamp:v1\0" . $userId . "\0"
            . $state['phone_event_version'] . "\0" . $state['phone_binding_hmac'], $this->phoneKey);
    }

    /**
     * To create a challenge after password proof, the caller must lock
     * the b_user row and call this function, then persist the returned
     * 64-hex token into the challenge within the SAME transaction.
     * This is an internal staging primitive, NOT a password verifier.
     */
    public function forLockedPasswordProof(PDO $db, int $userId, array $lockedUser): ?string
    {
        $this->requireConnection($db, true);
        if ($userId < 1 || $this->coverage->fullyCovered($db, $userId) !== true) {
            return null;
        }
        $passwordHash = $this->accountHash($userId, $lockedUser);
        if ($passwordHash === null) {
            return null;
        }
        $state = $this->readState($db, $userId, true);
        if ($state === null) {
            return null;
        }
        return hash_hmac('sha256', "evrasia:v5:auth-epoch-stamp:v1\0" . $userId . "\0"
            . $state['auth_epoch'] . "\0" . $passwordHash, $this->authKey);
    }

    public function stillCurrent(PDO $db, int $userId, array $lockedUser, string $challengeEpoch): ?bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $challengeEpoch) !== 1) {
            return null;
        }
        // The caller MUST already hold the same b_user row lock.
        $current = $this->forLockedPasswordProof($db, $userId, $lockedUser);
        return $current === null ? null : hash_equals($current, $challengeEpoch);
    }

    private function confirmedPhone(int $userId, array $phoneRow): ?string
    {
        if ($userId < 1
            || !V5BitrixSmsPhoneRows::matchesUserId($phoneRow['USER_ID'] ?? null, $userId)
            || ($phoneRow['CONFIRMED'] ?? null) !== 'Y'
            || !is_string($phoneRow['PHONE_NUMBER'] ?? null)
        ) {
            return null;
        }
        $phone = $this->phones->canonicalE164($phoneRow['PHONE_NUMBER']);
        return is_string($phone) && preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $phone) === 1
            ? $phone : null;
    }

    private function accountHash(int $userId, array $user): ?string
    {
        if ($userId < 1 || !V5BitrixSmsPhoneRows::matchesUserId($user['ID'] ?? null, $userId)
            || ($user['ACTIVE'] ?? null) !== 'Y' || ($user['BLOCKED'] ?? null) !== 'N'
            || ($user['PASSWORD_EXPIRED'] ?? null) !== 'N'
            || !is_string($user['PASSWORD'] ?? null) || $user['PASSWORD'] === ''
        ) {
            return null;
        }
        return $user['PASSWORD'];
    }

    /** @return array{auth_epoch:int,phone_event_version:int,phone_binding_hmac:?string}|null */
    private function readState(PDO $db, int $userId, bool $lock): ?array
    {
        if ($userId < 1) {
            return null;
        }
        $sql = 'SELECT USER_ID, AUTH_EPOCH, PHONE_EVENT_VERSION, PHONE_BINDING_HMAC '
            . 'FROM ev_samzaberu_auth_state WHERE USER_ID = ?'
            . ($lock ? ' FOR UPDATE' : '');
        $q = self::prepare($db, $sql);
        self::execute($q, [$userId]);
        $first = $q->fetch(PDO::FETCH_ASSOC);
        if ($first === false) {
            return null;
        }
        if (!is_array($first) || $q->fetch(PDO::FETCH_ASSOC) !== false
            || !V5BitrixSmsPhoneRows::matchesUserId($first['USER_ID'] ?? null, $userId)
        ) {
            return null;
        }
        $epoch = self::positiveInt($first['AUTH_EPOCH'] ?? null, false);
        $phoneVersion = self::positiveInt($first['PHONE_EVENT_VERSION'] ?? null, true);
        $binding = $first['PHONE_BINDING_HMAC'] ?? null;
        if ($epoch === null || $phoneVersion === null
            || !($binding === null || (is_string($binding)
                && preg_match('/\A[a-f0-9]{64}\z/D', $binding) === 1))
            || ($phoneVersion === 0 && $binding !== null)
            || ($phoneVersion > 0 && $binding === null && $lock === false)) {
            return null;
        }
        return ['auth_epoch'=>$epoch, 'phone_event_version'=>$phoneVersion,
            'phone_binding_hmac'=>$binding];
    }

    private function requireConnection(PDO $db, bool $transaction): void
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || ($transaction && !$db->inTransaction())
        ) {
            throw new RuntimeException('security_version_connection_unavailable');
        }
    }

    private static function positiveInt(mixed $input, bool $allowZero): ?int
    {
        if (!is_int($input) && !is_string($input)) return null;
        if (is_string($input) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $input) !== 1) return null;
        $num = filter_var($input, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => $allowZero ? 0 : 1]]);
        return $num === false ? null : $num;
    }

    private static function prepare(PDO $db, string $sql): PDOStatement
    {
        $q = $db->prepare($sql);
        if (!$q instanceof PDOStatement) throw new RuntimeException('security_version_query_unavailable');
        return $q;
    }

    private static function execute(PDOStatement $q, array $params): void
    {
        if (!$q->execute($params)) throw new RuntimeException('security_version_query_unavailable');
    }
}

/**
 * Only callable by a trusted Bitrix security-event adapter after acquiring
 * b_user -> b_sec_user -> b_user_phone_auth locks, and DURING the SAME PDO
 * transaction which makes the actual password/MFA/phone/security change.
 * This class does not install native event hooks or begin/commit transactions.
 */
final readonly class V5SecurityEventVersionWriter
{
    public function __construct(private V5SecurityEventVersions $versions) {}

    public function recordSecurityChanged(PDO $db, int $userId): void
    {
        $this->update($db, $userId, null, false);
    }

    public function recordPhoneConfirmed(PDO $db, int $userId, string $e164): void
    {
        // Current confirmed Bitrix phone is re-read under lock. Caller must
        // have updated native phone confirmation inside THIS transaction.
        $this->update($db, $userId, $e164, true);
    }

    public function recordPhoneRevoked(PDO $db, int $userId): void
    {
        $this->update($db, $userId, null, true);
    }

    private function update(PDO $db, int $userId, ?string $newPhone, bool $phoneEvent): void
    {
        if ($userId < 1 || !$db->inTransaction()
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
        ) {
            throw new RuntimeException('security_event_requires_shared_transaction');
        }
        // Exactly the V3C/SMS lock order, with shared b_user mutex first.
        $user = self::readOne($db,
            'SELECT ID FROM b_user WHERE ID = ? FOR UPDATE', $userId);
        if ($user === null || !V5BitrixSmsPhoneRows::matchesUserId($user['ID'] ?? null, $userId)) {
            throw new RuntimeException('security_event_user_unavailable');
        }
        // The native MFA row may be absent: same-user b_user mutex MUST
        // be acquired by native MFA enrollment flows as well.
        self::readOne($db,
            'SELECT USER_ID FROM b_sec_user WHERE USER_ID = ? FOR UPDATE', $userId);
        $phone = V5BitrixSmsPhoneRows::read($db, $userId, true);
        $binding = null;
        if ($phoneEvent) {
            if ($newPhone !== null) {
                if ($phone === null || ($phone['CONFIRMED'] ?? null) !== 'Y'
                    || !is_string($phone['PHONE_NUMBER'] ?? null)
                    || !hash_equals($phone['PHONE_NUMBER'], $newPhone)) {
                    throw new RuntimeException('security_event_phone_mismatch');
                }
                $binding = $this->versions->phoneBinding($userId, $newPhone);
            } elseif ($phone !== null && ($phone['CONFIRMED'] ?? null) === 'Y') {
                throw new RuntimeException('security_event_phone_not_revoked');
            }
        }
        $q = self::prepare($db,
            'SELECT AUTH_EPOCH, PHONE_EVENT_VERSION FROM ev_samzaberu_auth_state '
                . 'WHERE USER_ID = ? FOR UPDATE');
        self::execute($q, [$userId]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $q->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('security_event_state_missing');
        }
        $oldEpoch = self::positive($row['AUTH_EPOCH'] ?? null, false);
        $oldPhone = self::positive($row['PHONE_EVENT_VERSION'] ?? null, true);
        if ($oldEpoch === null || $oldPhone === null
            || $oldEpoch >= PHP_INT_MAX - 1 || ($phoneEvent && $oldPhone >= PHP_INT_MAX - 1)) {
            throw new RuntimeException('security_event_state_invalid');
        }
        if ($phoneEvent) {
            $q = self::prepare($db, 'UPDATE ev_samzaberu_auth_state SET '
                . 'AUTH_EPOCH = ?, PHONE_EVENT_VERSION = ?, PHONE_BINDING_HMAC = ? '
                . 'WHERE USER_ID = ? AND AUTH_EPOCH = ? AND PHONE_EVENT_VERSION = ?');
            self::execute($q, [$oldEpoch+1,$oldPhone+1,$binding,$userId,$oldEpoch,$oldPhone]);
        } else {
            $q = self::prepare($db, 'UPDATE ev_samzaberu_auth_state SET AUTH_EPOCH = ? '
                . 'WHERE USER_ID = ? AND AUTH_EPOCH = ?');
            self::execute($q, [$oldEpoch+1,$userId,$oldEpoch]);
        }
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('security_event_version_cas_failed');
        }
    }

    private static function readOne(PDO $db, string $sql, int $userId): ?array
    {
        $q=self::prepare($db,$sql);
        self::execute($q,[$userId]);
        $first=$q->fetch(PDO::FETCH_ASSOC);
        if ($first === false) return null;
        if (!is_array($first) || $q->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new RuntimeException('security_event_ambiguous');
        }
        return $first;
    }
    private static function positive(mixed $value, bool $zero): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) !== 1) return null;
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$zero?0:1]]);
        return $n===false?null:$n;
    }
    private static function prepare(PDO $db,string $sql): PDOStatement
    {
        $q=$db->prepare($sql);
        if (!$q instanceof PDOStatement)throw new RuntimeException('security_event_query_unavailable');
        return $q;
    }
    private static function execute(PDOStatement $q,array $params):void
    {
        if (!$q->execute($params))throw new RuntimeException('security_event_query_unavailable');
    }
}
