<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';
require_once __DIR__ . '/V5MySqlSmsOutbox.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Mandatory future Bitrix implementation. Not supplied or wired by this file.
 *
 * While b_user and shared AUTH_EPOCH are locked in the SAME owned transaction:
 *  - re-resolve the supplied login to the SAME user, no ambiguity;
 *  - verify entered password against the CURRENT native Bitrix password hash
 *    with its supported comparator, without establishing a PHP login;
 *  - recheck active/unblocked/nonexpired account and current native MFA TOTP;
 *  - recheck anti-fraud block and provide a server-keyed, 64-hex epoch stamp
 *    bound to the locked AUTH_EPOCH argument and native mutation coverage;
 *  - reject any unknown, stale or unavailable state.
 *
 * This closes the password-reset race between the first-factor HTTP check
 * and creation of a fresh 300-second challenge.
 */
interface V5LockedPasswordTotpChallengeProofPort
{
    public function reverifyCurrentPasswordAndTotpWithUserLock(
        PDO $db,
        PasswordVerifiedAccount $account,
        string $login,
        #[\SensitiveParameter] string $password,
        int $lockedAuthEpoch
    ): ?string;
}

/**
 * Staging-only candidate: issue an actual, password-bound TOTP challenge
 * using the proposed InnoDB table. NO SMS queue, no text, no provider calls,
 * no direct JWT issuance and no production registration.
 *
 * The deliberately public begin(userId) shortcut and resendSms remain
 * unavailable. An SMS lifecycle must be implemented separately only after
 * native phone version, 10-scope budget and actual delivery are attested.
 */
final readonly class V5MySqlPasswordBoundTotpIssuer
    implements V5PasswordBoundChallengeLifecyclePort
{
    private const TABLES=[
        'b_user','ev_samzaberu_auth_state','ev_v5_auth_challenges',
    ];
    private const MAX_PENDING_PER_ACCOUNT=20;

    public function __construct(
        private PDO $db,
        private V5LockedPasswordTotpChallengeProofPort $native,
        #[\SensitiveParameter] private string $deviceBindingKey,
        private int $ttlSeconds=300,
    ){
        if(strlen($deviceBindingKey)<32 || strlen($deviceBindingKey)>1024
            || $ttlSeconds<60 || $ttlSeconds>300)
            throw new InvalidArgumentException('V5 TOTP issuer policy unavailable');
    }

    public function begin(
        int $userId,string $deviceId,string $platform,ChallengeKind $kind
    ): PendingLoginChallenge {
        throw new AuthenticationRejected('authentication_failed');
    }

    public function resendSms(
        string $challengeId,string $deviceId,string $platform
    ): PendingLoginChallenge {
        throw new AuthenticationRejected('authentication_failed');
    }

    public function beginBound(
        PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        string $deviceId,string $platform,ChallengeKind $kind,
    ): PendingLoginChallenge {
        if($kind!==ChallengeKind::TOTP
            || !$account->accountAllowed || !$account->nativeTotpActive
            || !V5LoginWorkflow::validInstallation($deviceId,$platform)
            || $login==='' || strlen($login)>320
            || $password==='' || strlen($password)>4096)
            throw new AuthenticationRejected('authentication_failed');

        $this->assertReady();
        $started=false;
        try {
            if(!$this->db->beginTransaction())
                throw new RuntimeException('v5_challenge_tx_unavailable');
            $started=true;
            $now=self::nonnegative($this->query(
                'SELECT UNIX_TIMESTAMP()',[]
            )->fetchColumn());
            if($now===null || $now<1 || $now>PHP_INT_MAX-$this->ttlSeconds)
                throw new RuntimeException('v5_challenge_clock_unavailable');

            // Existing lock order: b_user first, then event epoch and V5 rows.
            $user=$this->query('SELECT ID, ACTIVE, BLOCKED, PASSWORD_EXPIRED '
                .'FROM b_user WHERE ID = ? FOR UPDATE',[$account->userId])
                ->fetch(PDO::FETCH_ASSOC);
            if(!is_array($user)
                || (string)($user['ID']??'')!==(string)$account->userId
                || ($user['ACTIVE']??null)!=='Y'
                || ($user['BLOCKED']??null)!=='N'
                || ($user['PASSWORD_EXPIRED']??null)!=='N')
                throw new AuthenticationRejected('authentication_failed');

            $epochRow=$this->query('SELECT AUTH_EPOCH '
                .'FROM ev_samzaberu_auth_state WHERE USER_ID = ? FOR UPDATE',
                [$account->userId])->fetch(PDO::FETCH_ASSOC);
            $epoch=self::nonnegative($epochRow['AUTH_EPOCH']??null);
            if($epoch===null || $epoch<1)
                throw new AuthenticationRejected('authentication_failed');

            // A fresh password recheck UNDER THIS LOCK is mandatory. No
            // password-proof snapshots, client claims or legacy trust accepted.
            $stamp=$this->native->reverifyCurrentPasswordAndTotpWithUserLock(
                $this->db,$account,$login,$password,$epoch
            );
            if(!is_string($stamp)
                || preg_match('/\A[a-f0-9]{64}\z/D',$stamp)!==1)
                throw new AuthenticationRejected('authentication_failed');

            // User mutex serializes this bound issuer across requests. Only
            // active/unexpired challenges count; no code/phone value is read.
            $pending=self::nonnegative($this->query(
                'SELECT COUNT(*) FROM ev_v5_auth_challenges '
                .'WHERE user_id = ? AND status = ? AND expires_at > ?',
                [$account->userId,'pending',$now]
            )->fetchColumn());
            if($pending===null || $pending>=self::MAX_PENDING_PER_ACCOUNT)
                throw new AuthenticationRejected('authentication_failed');

            $challengeId=bin2hex(random_bytes(32));
            $binding=V5MySqlSmsOutbox::installationBinding(
                $deviceId,$platform,$this->deviceBindingKey
            );
            $q=$this->query('INSERT INTO ev_v5_auth_challenges '
                .'(challenge_id,user_id,device_binding,platform,kind,status,'
                .'password_proven,issued_at,expires_at,failed_attempts,'
                .'sms_sent_count,last_sms_sent_at,verification_stamp,auth_epoch,'
                .'sms_code_hmac,current_delivery_id) '
                .'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',[
                $challengeId,$account->userId,$binding,$platform,'totp',
                'pending','Y',$now,$now+$this->ttlSeconds,0,0,
                null,null,$stamp,null,null,
            ]);
            if($q->rowCount()!==1)
                throw new RuntimeException('v5_challenge_insert_failed');

            if(!$this->db->commit())
                throw new RuntimeException('v5_challenge_commit_uncertain');
            $started=false;
            // A challenge ID is not an access/refresh token, and is returned
            // ONLY after the insertion has committed successfully.
            return new PendingLoginChallenge(
                $challengeId,ChallengeKind::TOTP,$this->ttlSeconds
            );
        }catch(Throwable $e){
            if($started && $this->db->inTransaction()){
                try{$this->db->rollBack();}catch(Throwable){}
            }
            if($e instanceof AuthenticationRejected)
                throw new AuthenticationRejected('authentication_failed');
            throw new RuntimeException('v5_challenge_unavailable');
        }
    }

    private function assertReady():void {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction())
            throw new RuntimeException('v5_challenge_connection_unavailable');
        $q=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if(!$q instanceof PDOStatement)
            throw new RuntimeException('v5_challenge_tables_unavailable');
        foreach(self::TABLES as $t){
            if(!$q->execute([$t]) || $q->fetchColumn()!=='InnoDB')
                throw new RuntimeException('v5_challenge_tables_unavailable');
        }
    }

    private function query(string $sql,array $args):PDOStatement {
        $q=$this->db->prepare($sql);
        if(!$q instanceof PDOStatement || !$q->execute($args))
            throw new RuntimeException('v5_challenge_sql_unavailable');
        return $q;
    }

    private static function nonnegative(mixed $v):?int {
        if(is_int($v))return $v>=0?$v:null;
        if(!is_string($v)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)return null;
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false?null:$n;
    }
}
