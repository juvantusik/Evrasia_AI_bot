<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';
require_once __DIR__ . '/V5MySqlSmsOutbox.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * The current b_user.EMAIL after a native password sign-in, never a client
 * parameter or cookie. Website ownership verification is not a prerequisite.
 * A keyed stamp binds it to the current email, password and AUTH_EPOCH.
 */
final readonly class V5LockedVerifiedEmail
{
    public function __construct(public string $address, public string $stamp)
    {
        if (strlen($address) > 254 || filter_var($address, FILTER_VALIDATE_EMAIL) === false
            || preg_match('/\A[a-f0-9]{64}\z/D', $stamp) !== 1) {
            throw new InvalidArgumentException('verified_email_unavailable');
        }
    }
}

/**
 * MANDATORY installed Bitrix adapter. Both methods execute while b_user and
 * the shared AUTH_EPOCH row are locked on the SAME physical SQL transaction.
 * No production implementation or permissive default supplied here.
 */
interface V5LockedEmailOtpProofPort
{
    public function verifyPasswordAndCurrentEmailWithLocks(
        PDO $db, PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        int $lockedEpoch
    ): ?V5LockedVerifiedEmail;

    public function stillCurrentWithLocks(
        PDO $db, int $userId, int $lockedEpoch, string $expectedStamp
    ): bool;
}

/**
 * Server-owned rate reservation under the already locked user/epoch row.
 * Must persist account/email/IP/device rate usage in the SAME transaction.
 * The caller supplies NO email recipient or SMS destination.
 */
interface V5EmailOtpRatePort
{
    public function reserveWithLocks(
        PDO $db,int $userId,string $deviceId,string $verifiedEmail
    ):bool;
}

/** Existing website mail transport can be adapted without copying it. */
interface V5EmailOtpTransportPort
{
    public function acceptedForDelivery(string $verifiedEmail, string $code): bool;
}

/**
 * Feature-only MySQL/InnoDB mobile email OTP. Never calls Bitrix Login(),
 * changes website login, or grants JWT. It requires a separately verified
 * native account/password/current-profile-email recheck and rate port.
 * No production installation or migration is performed by this source.
 *
 * Issuance: lock b_user -> AUTH_EPOCH -> native source proof/savepoint ->
 * reserve budget -> insert pending -> COMMIT -> mail transport ->
 * lock b_user/epoch/challenge -> current profile email -> mark accepted.
 * The code cannot verify while the mail operation is unresolved.
 */
final readonly class V5MySqlEmailOtp implements V5PasswordBoundChallengeLifecyclePort, V5EmailChallengeVerificationPort
{
    private const TABLES = [
        'b_user', 'ev_samzaberu_auth_state',
        'ev_v5_email_auth_challenges',
    ];
    private const MAX_PENDING = 5;
    private const MAX_FAILED = 3;

    public function __construct(
        private PDO $db,
        private V5LockedEmailOtpProofPort $native,
        private V5EmailOtpRatePort $rates,
        private V5EmailOtpTransportPort $mail,
        #[\SensitiveParameter] private string $bindingKey,
        #[\SensitiveParameter] private string $codeHmacKey,
        private int $ttl = 300,
    ) {
        if (strlen($bindingKey) < 32 || strlen($codeHmacKey) < 32
            || hash_equals($bindingKey,$codeHmacKey)
            || $ttl < 60 || $ttl > 300) {
            throw new InvalidArgumentException('email_otp_keys_or_policy_unavailable');
        }
    }

    public function begin(
        int $userId, string $deviceId, string $platform, ChallengeKind $kind
    ): PendingLoginChallenge {
        throw new AuthenticationRejected('challenge_unavailable');
    }

    public function resendSms(
        string $challengeId, string $deviceId, string $platform
    ): PendingLoginChallenge {
        throw new AuthenticationRejected('challenge_unavailable');
    }

    public function beginBound(
        PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        string $deviceId,
        string $platform,
        ChallengeKind $kind,
    ): PendingLoginChallenge {
        if ($kind !== ChallengeKind::EMAIL || !$account->accountAllowed
            || $account->nativeTotpActive
            || !V5LoginWorkflow::validInstallation($deviceId,$platform)
            || $login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        $this->ready();
        $started=false;
        $challengeId=''; $email=''; $code='';
        try {
            if (!$this->db->beginTransaction()) throw new RuntimeException('email_begin');
            $started=true;
            $now=$this->now();
            $this->lockedAccount($account->userId);
            $epoch=$this->lockedEpoch($account->userId);
            // The strict V5 bridge already owns an independent continuity guard.
            // Nested named savepoints are invalidated when that older guard is released.
            $this->assertCallbackContinuity();
            $identity=$this->native->verifyPasswordAndCurrentEmailWithLocks(
                $this->db,$account,$login,$password,$epoch
            );
            $this->assertCallbackContinuity();
            if (!$this->db->inTransaction() || !$identity instanceof V5LockedVerifiedEmail) {
                throw new RuntimeException('email_identity_or_scope_invalid');
            }
            // Rate callbacks are site-specific, and may use native Bitrix.
            // An implicit COMMIT+BEGIN would lose the original b_user/epoch
            // locks while PDO could still report inTransaction()===true.
            $this->assertCallbackContinuity();
            $rateAllowed=$this->rates->reserveWithLocks(
                $this->db,$account->userId,$deviceId,$identity->address
            ) === true;
            $this->assertCallbackContinuity();
            if (!$this->db->inTransaction() || !$rateAllowed) {
                throw new RuntimeException('email_rate_denied');
            }
            // The locked account row serializes all competing issuances.
            $count=(int)$this->q(
                'SELECT COUNT(*) FROM ev_v5_email_auth_challenges '
                .'WHERE user_id = ? AND status IN (?,?) AND expires_at > ?',
                [$account->userId,'pending','accepted',$now]
            )->fetchColumn();
            if ($count >= self::MAX_PENDING) throw new RuntimeException('email_pending_limit');
            $challengeId=bin2hex(random_bytes(32));
            $code=(string)random_int(100000,999999);
            $digest=hash_hmac('sha256',"evrasia:v5:email-otp:v1\0".$challengeId."\0".$code,
                $this->codeHmacKey);
            $binding=V5MySqlSmsOutbox::installationBinding(
                $deviceId,$platform,$this->bindingKey
            );
            $row=$this->q(
                'INSERT INTO ev_v5_email_auth_challenges '
                .'(challenge_id,user_id,device_binding,platform,status,email_stamp,'
                .'auth_epoch,code_hmac,issued_at,expires_at,failed_attempts) '
                .'VALUES (?,?,?,?,?,?,?,?,?,?,?)',[
                    $challengeId,$account->userId,$binding,$platform,'pending',
                    $identity->stamp,$epoch,$digest,$now,$now+$this->ttl,0,
                ]
            );
            if ($row->rowCount() !== 1 || !$this->db->commit()) {
                throw new RuntimeException('email_insert_or_commit_uncertain');
            }
            $started=false;
            $email=$identity->address;

            // Mail transport runs outside the SQL locks. A queued/unconfirmed
            // message does not become a valid OTP unless the transport accepted it.
            $accepted=false;
            try { $accepted=$this->mail->acceptedForDelivery($email,$code) === true; }
            catch (Throwable) {}
            if (!$accepted) throw new RuntimeException('email_delivery_unconfirmed');

            // A concurrent password reset/email edit/revoke between issue and
            // delivery receipt MUST invalidate the code.
            if (!$this->db->beginTransaction()) throw new RuntimeException('email_ack_begin');
            $started=true;
            $this->lockedAccount($account->userId);
            $current=$this->lockedEpoch($account->userId);
            $this->assertCallbackContinuity();
            $still=$current === $epoch
                && $this->native->stillCurrentWithLocks(
                    $this->db,$account->userId,$epoch,$identity->stamp
                );
            $this->assertCallbackContinuity();
            if (!$still || !$this->db->inTransaction()) throw new RuntimeException('email_changed');
            $write=$this->q(
                'UPDATE ev_v5_email_auth_challenges SET status = ? '
                .'WHERE challenge_id = ? AND user_id = ? AND status = ? '
                .'AND auth_epoch = ? AND email_stamp = ? AND expires_at > ?',[
                    'accepted',$challengeId,$account->userId,'pending',$epoch,
                    $identity->stamp,$this->now(),
                ]
            );
            if ($write->rowCount() !== 1 || !$this->db->commit()) {
                throw new RuntimeException('email_ack_failed');
            }
            $started=false;
            return new PendingLoginChallenge($challengeId,ChallengeKind::EMAIL,$this->ttl);
        } catch (Throwable) {
            $this->rollbackIfOwned($started);
            throw new AuthenticationRejected('challenge_unavailable');
        } finally {
            // No code/recipient is written to the challenge table or log.
            $code=''; $email='';
        }
    }

    /** Public bridge routes to email only after this indexed existence check. */
    public function ownsEmailChallenge(string $challengeId): bool
    {
        if (!V5LoginWorkflow::validChallengeId($challengeId)) return false;
        $this->ready();
        return $this->q('SELECT challenge_id FROM ev_v5_email_auth_challenges '
            .'WHERE challenge_id = ? LIMIT 1',[$challengeId])->fetchColumn()
            === $challengeId;
    }

    /** One-time consume only. MUST be used by a dedicated email HTTP bridge. */
    public function verifyAndConsume(
        string $challengeId,
        #[\SensitiveParameter] string $code,
        string $deviceId,
        string $platform
    ): ?ConsumedLoginChallenge {
        if (!V5LoginWorkflow::validChallengeId($challengeId)
            || preg_match('/\A[0-9]{6}\z/D',$code)!==1
            || !V5LoginWorkflow::validInstallation($deviceId,$platform)) {
            return null;
        }
        $this->ready();
        $started=false;
        try {
            // Indexed point lookup before the transaction only identifies
            // which b_user row to lock FIRST; it is never authorization.
            $r=$this->q('SELECT user_id FROM ev_v5_email_auth_challenges '
                .'WHERE challenge_id = ? LIMIT 1',[$challengeId])->fetch(PDO::FETCH_ASSOC);
            $uid=self::uint($r['user_id']??null);
            if ($uid === null || $uid < 1) return null;
            if (!$this->db->beginTransaction()) throw new RuntimeException('email_verify_begin');
            $started=true;
            $this->lockedAccount($uid);
            $epoch=$this->lockedEpoch($uid);
            $r=$this->q('SELECT challenge_id,user_id,device_binding,platform,status,'
                .'email_stamp,auth_epoch,code_hmac,issued_at,expires_at,failed_attempts '
                .'FROM ev_v5_email_auth_challenges WHERE challenge_id = ? FOR UPDATE',
                [$challengeId])->fetch(PDO::FETCH_ASSOC);
            $now=$this->now();
            $binding=V5MySqlSmsOutbox::installationBinding(
                $deviceId,$platform,$this->bindingKey
            );
            if (!is_array($r) || ($r['challenge_id']??null)!==$challengeId
                || self::uint($r['user_id']??null)!==$uid
                || ($r['platform']??null)!==$platform
                || !is_string($r['device_binding']??null)
                || !hash_equals($r['device_binding'],$binding)
                || ($r['status']??null)!=='accepted'
                || self::uint($r['auth_epoch']??null)!==$epoch
                || self::uint($r['issued_at']??null)===null
                || self::uint($r['expires_at']??null)===null
                || (int)$r['issued_at']>$now || (int)$r['expires_at']<=$now
                || (int)$r['expires_at']-(int)$r['issued_at']>$this->ttl
                || self::uint($r['failed_attempts']??null)===null
                || (int)$r['failed_attempts']>=self::MAX_FAILED
                || !is_string($r['email_stamp']??null)
                || preg_match('/\A[a-f0-9]{64}\z/D',$r['email_stamp'])!==1
                || !is_string($r['code_hmac']??null)
                || preg_match('/\A[a-f0-9]{64}\z/D',$r['code_hmac'])!==1) {
                $this->db->rollBack();$started=false;
                return null;
            }
            $this->assertCallbackContinuity();
            $current=$this->native->stillCurrentWithLocks(
                $this->db,$uid,$epoch,$r['email_stamp']
            );
            $this->assertCallbackContinuity();
            if (!$this->db->inTransaction() || !$current) {
                throw new RuntimeException('email_native_eligibility');
            }
            $expected=hash_hmac('sha256',"evrasia:v5:email-otp:v1\0".$challengeId."\0".$code,
                $this->codeHmacKey);
            $valid=hash_equals($r['code_hmac'],$expected);
            $attempts=(int)$r['failed_attempts'];
            $update=$valid
                ? $this->q('UPDATE ev_v5_email_auth_challenges '
                    .'SET status = ?, code_hmac = NULL WHERE challenge_id = ? '
                    .'AND status = ? AND failed_attempts = ?',[
                        'consumed',$challengeId,'accepted',$attempts,
                    ])
                : $this->q('UPDATE ev_v5_email_auth_challenges '
                    .'SET failed_attempts = ?, status = ? WHERE challenge_id = ? '
                    .'AND status = ? AND failed_attempts = ?',[
                        $attempts+1,$attempts+1>=self::MAX_FAILED?'cancelled':'accepted',
                        $challengeId,'accepted',$attempts,
                    ]);
            if ($update->rowCount() !== 1 || !$this->db->commit()) {
                throw new RuntimeException('email_cas_or_commit_failed');
            }
            $started=false;
            return $valid
                ? new ConsumedLoginChallenge($uid,$deviceId,$platform,
                    ChallengeKind::EMAIL,$challengeId)
                : null;
        } catch (Throwable) {
            $this->rollbackIfOwned($started);
            return null;
        }
    }

    private function ready(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()) {
            throw new RuntimeException('email_sql_unavailable');
        }
        $q=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if (!$q instanceof PDOStatement) throw new RuntimeException('email_tables_missing');
        foreach (self::TABLES as $table) {
            if (!$q->execute([$table]) || $q->fetchColumn()!=='InnoDB') {
                throw new RuntimeException('email_tables_missing');
            }
        }
    }

    private function lockedAccount(int $uid): void
    {
        $r=$this->q('SELECT ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED '
            .'FROM b_user WHERE ID = ? FOR UPDATE',[$uid])->fetch(PDO::FETCH_ASSOC);
        if (!is_array($r) || self::uint($r['ID']??null)!==$uid
            || ($r['ACTIVE']??null)!=='Y'
            || ($r['BLOCKED']??null)!=='N'
            || ($r['PASSWORD_EXPIRED']??null)!=='N') {
            throw new RuntimeException('email_account_denied');
        }
    }

    private function lockedEpoch(int $uid): int
    {
        $r=$this->q('SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            .'WHERE USER_ID = ? FOR UPDATE',[$uid])->fetch(PDO::FETCH_ASSOC);
        $epoch=self::uint($r['AUTH_EPOCH']??null);
        if ($epoch===null || $epoch<1) throw new RuntimeException('email_epoch_denied');
        return $epoch;
    }

    private function now(): int
    {
        $v=self::uint($this->q('SELECT UNIX_TIMESTAMP()',[])->fetchColumn());
        if ($v===null || $v<1 || $v>PHP_INT_MAX-$this->ttl)
            throw new RuntimeException('email_clock');
        return $v;
    }

    private static function uint(mixed $v): ?int
    {
        if (is_int($v)) return $v>=0?$v:null;
        if (!is_string($v) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)
            return null;
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false?null:$n;
    }

    private function q(string $sql,array $args): PDOStatement
    {
        $p=$this->db->prepare($sql);
        if (!$p instanceof PDOStatement || !$p->execute($args))
            throw new RuntimeException('email_query_failed');
        return $p;
    }

    /**
     * The bridge's original random savepoint is the sole continuity authority.
     * Releasing it destroys all newer nested savepoints in MySQL; a second
     * named guard here caused SQL error 1305 on email challenge issuance.
     * Verify this same guard before and after every native callback instead.
     */
    private function assertCallbackContinuity(): void
    {
        if ($this->db instanceof V5BitrixPdoCompatibilityBridge) {
            $this->db->assertOwnedNativeAuthMutationContinuity(
                \Bitrix\Main\Application::getConnection()
            );
        } elseif (!$this->db->inTransaction()) {
            throw new RuntimeException('email_callback_transaction_lost');
        }
    }

    private function rollbackIfOwned(bool $started): void
    {
        if (!$started || !$this->db->inTransaction()) return;
        // The strict bridge itself refuses to roll back an uncertain owner.
        try { $this->db->rollBack(); } catch (Throwable) {}
    }
}
