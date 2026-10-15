<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';
require_once __DIR__ . '/V5SessionTokens.php';
require_once __DIR__ . '/V5SessionAssurancePolicy.php';
require_once __DIR__ . '/V5SecurityEventVersions.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * No permissive/default implementation. Only a trusted site provider may
 * perform the FULL fresh native password/MFA/trust/grant re-check under the
 * locked b_user row in the SAME transaction as session insertion.
 * For consumed grants it MUST reject a fabricated/replayed grant and must
 * coordinate with the actual durable V5 challenge-consume implementation.
 */
interface V5LockedStrongSessionProofPort
{
    public function verifiedTrustWithUserLock(
        PDO $db, PasswordVerifiedAccount $account,
        string $deviceId, string $platform, string $trustToken
    ): bool;

    public function verifiedConsumedGrantWithUserLock(
        PDO $db, ConsumedLoginChallenge $grant
    ): bool;
}

/**
 * STAGING CANDIDATE ONLY: durable V5 session, access signing after commit,
 * one-use refresh rotation and revocation. Never wire to /api/v5 without
 * attested isolated Bitrix/MySQL staging and native mutation/epoch coverage.
 *
 * Lock discipline: no nested transaction; b_user FIRST; proof / mandatory
 * coverage; shared auth_state; session row last. Refresh digest index is read
 * WITHOUT lock before beginning so the actual lock order stays b_user first.
 * No raw bearer, password, trust token, OTP or device ID is persisted.
 */
/**
 * Site-only, READ-ONLY reader for /loyalty/pin after an already-validated
 * strong V5 access JWT. It MUST use this exact PDO/Bitrix transaction,
 * never open a second connection, mutate state or trigger SMS.
 * No production implementation is installed.
 */
interface V5LockedBonusPinReaderPort
{
    public function readLockedBonusPin(PDO $db, int $userId): ?string;
}

final readonly class V5MySqlStrongSessionStore implements V5StrongSessionPort
{
    private const TABLES = [
        'b_user', 'ev_samzaberu_auth_state', 'ev_v5_strong_sessions',
    ];

    public function __construct(
        private PDO $db,
        private V5AccessJwtCodec $access,
        private V5OpaqueRefreshTokens $refreshTokens,
        private V5LockedStrongSessionProofPort $proof,
        private V5NativeSecurityHookCoveragePort $nativeCoverage,
        #[\SensitiveParameter] private string $bindingKey,
        private int $sessionTtlSeconds = 86400,
    ) {
        if (strlen($bindingKey) < 32 || strlen($bindingKey) > 1024
            || $sessionTtlSeconds < 300 || $sessionTtlSeconds > 604800) {
            throw new InvalidArgumentException('V5 session policy unavailable');
        }
    }

    public function afterTrustedDevice(
        PasswordVerifiedAccount $account,
        string $deviceId,
        string $platform,
        string $trustToken,
    ): AuthenticatedV5Session {
        if (!$account->accountAllowed || $account->nativeTotpActive
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || $trustToken === '' || strlen($trustToken) > 1024
        ) {
            throw new AuthenticationRejected('authentication_failed');
        }
        return $this->issue($account->userId, $deviceId, $platform, 'trusted',
            null, function () use ($account, $deviceId, $platform, $trustToken): bool {
                return $this->proof->verifiedTrustWithUserLock(
                    $this->db, $account, $deviceId, $platform, $trustToken
                ) === true;
            });
    }

    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session
    {
        return $this->issue($grant->userId, $grant->deviceId, $grant->platform,
            $grant->kind->value, $grant->grantReference,
            fn(): bool => $this->proof->verifiedConsumedGrantWithUserLock(
                $this->db, $grant
            ) === true);
    }

    /**
     * One-use refresh. Caller must NEVER accept V4 refresh credentials.
     * Returns a completely new access JWT + opaque refresh token after commit.
     * Inactive/expired/changed-epoch sessions cannot be silently upgraded.
     */
    public function rotateRefresh(#[\SensitiveParameter] string $presented): AuthenticatedV5Session
    {
        $digest = $this->refreshTokens->digestPresented($presented);
        if ($digest === null) throw new AuthenticationRejected('authentication_failed');
        $this->assertReady();

        // Unlocked lookup is ONLY a hint to lock b_user first. The digest is
        // re-verified against the freshly locked session inside the transaction.
        $hint = $this->unique('SELECT sid, user_id FROM ev_v5_strong_sessions '
            . 'WHERE refresh_digest = ? LIMIT 2', [$digest]);
        $sid = $hint['sid'] ?? null;
        $userId = self::positive($hint['user_id'] ?? null);
        if (!is_string($sid) || !self::hex64($sid) || $userId === null) {
            throw new AuthenticationRejected('authentication_failed');
        }

        return $this->transaction(function () use ($digest, $sid, $userId): AuthenticatedV5Session {
            $now = $this->dbTime();
            $this->lockUser($userId);
            $this->requireNativeCoverage($userId);
            $epoch = $this->lockedEpoch($userId);
            $row = $this->lockedSession($sid);
            $state = self::state($row, $userId, $now);
            $generation = self::positive($row['refresh_generation'] ?? null);
            if ($state === null || $generation === null || $generation >= PHP_INT_MAX - 1
                || $state['auth_epoch'] !== $epoch
                || !is_string($row['refresh_digest'] ?? null)
                || !hash_equals($row['refresh_digest'], $digest)
            ) {
                throw new AuthenticationRejected('authentication_failed');
            }
            $next = $this->refreshTokens->issue();
            $q = $this->query('UPDATE ev_v5_strong_sessions SET refresh_digest = ?, '
                . 'refresh_generation = ?, updated_at = ? '
                . 'WHERE sid = ? AND refresh_digest = ? AND refresh_generation = ? '
                . 'AND auth_epoch = ? AND status = ?', [
                $next['digest'], $generation + 1, $now, $sid, $digest,
                $generation, $epoch, 'active',
            ]);
            if ($q->rowCount() !== 1) {
                throw new AuthenticationRejected('authentication_failed');
            }
            // Prepare access claims from a row checked under the exclusive
            // transaction; JWT signing only occurs AFTER successful commit.
            return new AuthenticatedV5Session(
                $this->access->issueForCommittedSession($state, $now),
                $next['token']
            );
        });
    }

    /**
     * Revoke this one V5 session, never another user's session and never V4.
     * Refresh and logout serialize on b_user then session; old JWTs still need
     * a server-side revocation/epoch check at EVERY protected action.
     */
    public function revokeAccess(#[\SensitiveParameter] string $accessJwt): bool
    {
        $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
        if ($claims === null) return false;
        $this->assertReady();
        $userId = self::positive($claims['sub'] ?? null);
        if ($userId === null) return false;
        try {
            return $this->transaction(function () use ($claims, $userId): bool {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = self::state($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)) {
                    return false;
                }
                $q = $this->query('UPDATE ev_v5_strong_sessions SET status = ?, updated_at = ? '
                    . 'WHERE sid = ? AND status = ? AND auth_epoch = ?', [
                    'revoked', $now, $claims['sid'], 'active', $epoch,
                ]);
                return $q->rowCount() === 1;
            });
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Read-only assurance snapshot, NOT authorization to perform a DB mutation
     * after the transaction has ended. A sensitive endpoint MUST run its
     * protected operation in the SAME locked authorization transaction.
     * Caller supplies a signature-validated V5 token; we validate it again.
     * @return array<string,mixed>|null
     */
    public function currentAssuranceSnapshot(#[\SensitiveParameter] string $jwt): ?array
    {
        $claims = $this->access->verifyAccess($jwt, $this->peekTime());
        if ($claims === null) return null;
        $this->assertReady();
        $userId = self::positive($claims['sub'] ?? null);
        if ($userId === null) return null;
        try {
            return $this->transaction(function () use ($claims, $userId): ?array {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = self::state($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)) {
                    return null;
                }
                return $state;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Protected bonus-PIN retrieval. Authorization and the private read MUST
     * happen under the SAME owned transaction and b_user mutex. Unlike
     * currentAssuranceSnapshot(), this does not release the session lock
     * before reading the protected value. There is no additional SMS proof:
     * access must already come from strong TOTP, SMS or enrolled trust.
     *
     * Returns null for ALL denied/unknown states; never log the bearer or PIN.
     * The site PIN reader is intentionally unimplemented until staging.
     */
    public function showBonusPinForAccess(
        #[\SensitiveParameter] string $accessJwt,
        V5LockedBonusPinReaderPort $reader
    ): ?string {
        try {
            $claims = $this->access->verifyAccess($accessJwt, $this->peekTime());
            if ($claims === null) return null;
            $this->assertReady();
            $userId = self::positive($claims['sub'] ?? null);
            if ($userId === null) return null;
            return $this->transaction(function () use ($claims, $userId, $reader): ?string {
                $now = $this->dbTime();
                $this->lockUser($userId);
                $this->requireNativeCoverage($userId);
                $epoch = $this->lockedEpoch($userId);
                $row = $this->lockedSession($claims['sid']);
                $state = self::state($row, $userId, $now);
                if ($state === null || $epoch !== $state['auth_epoch']
                    || !V5SessionAssurancePolicy::permitsProtectedAction($claims, $state, $now)
                ) return null;

                $pin = $reader->readLockedBonusPin($this->db, $userId);
                // Never guess a site-specific PIN format or strip leading zeros.
                // Refuse unexpectedly large values and binary control bytes.
                return is_string($pin) && $pin !== ''
                    && strlen($pin) <= 128
                    && preg_match('/[\x00-\x1f\x7f]/', $pin) !== 1
                    ? $pin : null;
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * All issuance gates must pass before inserting any session. The mandatory
     * injected proof ports intentionally have NO production implementation.
     */
    private function issue(
        int $userId, string $deviceId, string $platform, string $assurance,
        ?string $grantReference, callable $proof,
    ): AuthenticatedV5Session {
        if ($userId < 1 || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || !in_array($assurance, ['totp','sms','trusted'], true)
            || ($grantReference !== null && !V5LoginWorkflow::validChallengeId($grantReference))
            || ($grantReference === null && $assurance !== 'trusted')
            || ($grantReference !== null && $assurance === 'trusted')) {
            throw new AuthenticationRejected('authentication_failed');
        }
        $this->assertReady();
        return $this->transaction(function () use (
            $userId, $deviceId, $platform, $assurance, $grantReference, $proof
        ): AuthenticatedV5Session {
            $now = $this->dbTime();
            if ($now > PHP_INT_MAX - $this->sessionTtlSeconds) {
                throw new RuntimeException('session_clock_unavailable');
            }
            $this->lockUser($userId);
            // The proof port may inspect native MFA/verified phone, but MUST
            // not independently commit or start a nested transaction.
            if ($proof() !== true) throw new AuthenticationRejected('authentication_failed');
            $this->requireNativeCoverage($userId);
            $epoch = $this->lockedEpoch($userId);
            $sid = bin2hex(random_bytes(32));
            $refresh = $this->refreshTokens->issue();
            $grantDigest = $grantReference === null ? null :
                hash_hmac('sha256', "evrasia:v5:consumed-grant:v1\0" . $grantReference,
                    $this->bindingKey);
            $deviceBinding = hash_hmac('sha256', "evrasia:v5:device-binding:v1\0"
                . $deviceId . "\0" . $platform, $this->bindingKey);
            $q = $this->query('INSERT INTO ev_v5_strong_sessions '
                . '(sid, user_id, auth_epoch, assurance, device_binding, platform, '
                . 'assured_at, expires_at, status, refresh_digest, refresh_generation, '
                . 'grant_digest, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $sid, $userId, $epoch, $assurance, $deviceBinding, $platform,
                $now, $now + $this->sessionTtlSeconds, 'active',
                $refresh['digest'], 1, $grantDigest, $now, $now,
            ]);
            if ($q->rowCount() !== 1) throw new RuntimeException('session_insert_failed');
            $state = ['version'=>'v5','status'=>'active','revocation_verified'=>true,
                'sid'=>$sid,'user_id'=>$userId,'auth_epoch'=>$epoch,
                'assurance'=>$assurance,'assured_at'=>$now,
                'expires_at'=>$now+$this->sessionTtlSeconds];
            return new AuthenticatedV5Session(
                $this->access->issueForCommittedSession($state, $now),
                $refresh['token']
            );
        });
    }

    private function assertReady(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()) {
            throw new RuntimeException('session_connection_unavailable');
        }
        $q=$this->prepare('SELECT ENGINE FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        foreach (self::TABLES as $name) {
            if (!$q->execute([$name]) || $q->fetchColumn() !== 'InnoDB') {
                throw new RuntimeException('session_table_unavailable');
            }
        }
    }

    /** A transaction owned by this method only. Deny on uncertain commit. */
    private function transaction(callable $operation): mixed
    {
        $started=false;
        try {
            if (!$this->db->beginTransaction()) {
                throw new RuntimeException('session_begin_unavailable');
            }
            $started=true;
            $result=$operation();
            if (!$this->db->commit()) throw new RuntimeException('session_commit_unavailable');
            $started=false;
            return $result;
        } catch (Throwable $error) {
            if ($started && $this->db->inTransaction()) {
                try { $this->db->rollBack(); } catch (Throwable) { /* deny */ }
            }
            // Distinguish rejected credentials from an unavailable native DB,
            // without exposing account status, token generation, or SQL.
            if ($error instanceof AuthenticationRejected) {
                throw new AuthenticationRejected('authentication_failed');
            }
            throw new RuntimeException('session_unavailable');
        }
    }

    private function peekTime(): int
    {
        $this->assertReady();
        return $this->dbTime();
    }

    private function dbTime(): int
    {
        $q=$this->query('SELECT UNIX_TIMESTAMP()', []);
        $now=self::positive($q->fetchColumn());
        if ($now === null || $now >= PHP_INT_MAX - 604800) {
            throw new RuntimeException('session_clock_unavailable');
        }
        return $now;
    }

    private function lockUser(int $userId): void
    {
        $r=$this->unique('SELECT ID, ACTIVE, BLOCKED, PASSWORD_EXPIRED FROM b_user '
            . 'WHERE ID = ? FOR UPDATE', [$userId]);
        if ($r === null || self::positive($r['ID'] ?? null) !== $userId
            || ($r['ACTIVE'] ?? null) !== 'Y'
            || ($r['BLOCKED'] ?? null) !== 'N'
            || ($r['PASSWORD_EXPIRED'] ?? null) !== 'N') {
            throw new AuthenticationRejected('authentication_failed');
        }
    }

    private function requireNativeCoverage(int $userId): void
    {
        if ($this->nativeCoverage->fullyCovered($this->db, $userId) !== true) {
            throw new RuntimeException('native_epoch_coverage_unverified');
        }
    }

    private function lockedEpoch(int $userId): int
    {
        $r=$this->unique('SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            . 'WHERE USER_ID = ? FOR UPDATE', [$userId]);
        $epoch=self::positive($r['AUTH_EPOCH'] ?? null);
        if ($epoch === null) throw new RuntimeException('native_epoch_unavailable');
        return $epoch;
    }

    /** @return array<string,mixed>|null */
    private function lockedSession(string $sid): ?array
    {
        return $this->unique('SELECT sid, user_id, auth_epoch, assurance, assured_at, '
            . 'expires_at, status, refresh_digest, refresh_generation '
            . 'FROM ev_v5_strong_sessions WHERE sid = ? FOR UPDATE', [$sid]);
    }

    /** @param array<string,mixed>|null $row @return array<string,mixed>|null */
    private static function state(?array $row, int $userId, int $now): ?array
    {
        if ($row === null || ($row['sid'] ?? null) === null
            || !is_string($row['sid']) || !self::hex64($row['sid'])
            || self::positive($row['user_id'] ?? null) !== $userId
            || ($row['status'] ?? null) !== 'active'
            || !in_array($row['assurance'] ?? null, ['totp','sms','trusted'], true)
        ) return null;
        $epoch=self::positive($row['auth_epoch'] ?? null);
        $assured=self::positive($row['assured_at'] ?? null);
        $expires=self::positive($row['expires_at'] ?? null);
        if ($epoch === null || $assured === null || $expires === null
            || $assured > $now || $expires <= $now || $expires <= $assured) return null;
        return ['version'=>'v5','status'=>'active','revocation_verified'=>true,
            'sid'=>$row['sid'],'user_id'=>$userId,'auth_epoch'=>$epoch,
            'assurance'=>$row['assurance'],'assured_at'=>$assured,'expires_at'=>$expires];
    }

    /** @return array<string,mixed>|null */
    private function unique(string $sql, array $params): ?array
    {
        $q=$this->query($sql,$params);
        $first=$q->fetch(PDO::FETCH_ASSOC);
        if ($first===false) return null;
        if (!is_array($first) || $q->fetch(PDO::FETCH_ASSOC)!==false) {
            throw new RuntimeException('session_row_ambiguous');
        }
        return $first;
    }

    private function query(string $sql, array $params): PDOStatement
    {
        $q=$this->prepare($sql);
        if (!$q->execute($params)) throw new RuntimeException('session_query_unavailable');
        return $q;
    }

    private function prepare(string $sql): PDOStatement
    {
        $q=$this->db->prepare($sql);
        if (!$q instanceof PDOStatement) throw new RuntimeException('session_query_unavailable');
        return $q;
    }

    private static function positive(mixed $value): ?int
    {
        if (!is_int($value) && !is_string($value)) return null;
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D',$value)!==1)return null;
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $n===false?null:$n;
    }

    private static function hex64(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D',$value)===1;
    }
}
