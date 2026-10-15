<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlSmsOutbox.php';
require_once __DIR__ . '/ChallengeRules.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * MANDATORY native provider; NO permissive implementation.
 *
 * Must verify current active/unblocked account, native TOTP priority, shared
 * AUTH_EPOCH and (for SMS) CURRENT verified-phone stamp, with Bitrix rows
 * locked on the EXACT SAME connection/transaction. TOTP verification MUST
 * consume native replay state transactionally; never use a parallel TOTP secret.
 */
interface V5LockedChallengeNativeFactorPort
{
    public function currentFactorEligibleWithLocks(
        PDO $db, int $userId, ChallengeKind $kind,
        string $challengeAuthEpoch, ?string $verifiedPhoneStamp
    ): bool;

    public function consumeNativeTotpWithLocks(
        PDO $db, int $userId, #[\SensitiveParameter] string $code
    ): bool;
}

/**
 * One-time verification/consumption ONLY for existing, password-proven
 * challenges. It does NOT issue the original challenge, reserve SMS quotas,
 * dispatch a paid SMS, or grant a V5 session. Use a native locked state port.
 *
 * Existing MySQL V5 sms schema is used as-is; no new production DDL.
 * Challenge row is locked first, consistent with V5MySqlSmsOutbox.
 * Future real Bitrix staging MUST separately attest native SQL/ORM lock order.
 */
final readonly class V5MySqlChallengeConsumption
{
    private const TABLES = [
        'b_user', 'ev_samzaberu_auth_state',
        'ev_v5_auth_challenges', 'ev_v5_sms_outbox',
    ];

    public function __construct(
        private PDO $db,
        private V5LockedChallengeNativeFactorPort $native,
        #[\SensitiveParameter] private string $deviceBindingKey,
        #[\SensitiveParameter] private string $smsCodeHmacKey,
    ) {
        if (strlen($deviceBindingKey) < 32
            || strlen($smsCodeHmacKey) < 32
            || hash_equals($deviceBindingKey,$smsCodeHmacKey)
        ) throw new InvalidArgumentException('V5 challenge security keys unavailable');
    }

    public function verifyAndConsume(
        string $challengeId,
        #[\SensitiveParameter] string $code,
        string $deviceId,
        string $platform,
    ): ?ConsumedLoginChallenge {
        if (!V5LoginWorkflow::validChallengeId($challengeId)
            || preg_match('/\A[0-9]{6}\z/D',$code)!==1
            || !V5LoginWorkflow::validInstallation($deviceId,$platform)
        ) return null;

        $this->assertReady();
        $started=false;
        try {
            if (!$this->db->beginTransaction()) {
                throw new RuntimeException('v5_challenge_begin_failed');
            }
            $started=true;
            $now=$this->dbNow();
            $q=$this->execute('SELECT challenge_id,user_id,device_binding,platform,kind,'
                .'status,password_proven,issued_at,expires_at,failed_attempts,'
                .'sms_sent_count,verification_stamp,auth_epoch,sms_code_hmac,'
                .'current_delivery_id FROM ev_v5_auth_challenges '
                .'WHERE challenge_id = ? FOR UPDATE',[$challengeId]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            $record=self::record($row);
            $binding=V5MySqlSmsOutbox::installationBinding(
                $deviceId,$platform,$this->deviceBindingKey);
            if($record===null
                || ($record['challenge_id']??null)!==$challengeId
                || ($record['platform']??null)!==$platform
                || !ChallengeRules::canVerify(
                    $record,$record['user_id'],$binding,$record['kind'],$now
                )) {
                $this->db->rollBack();$started=false;
                return null;
            }
            $kind=ChallengeKind::from($record['kind']);
            if($this->native->currentFactorEligibleWithLocks(
                $this->db,$record['user_id'],$kind,
                $record['auth_epoch'],$record['verification_stamp']
            ) !== true) {
                $this->db->rollBack();$started=false;
                return null;
            }

            $valid=false;
            if($kind===ChallengeKind::SMS) {
                $sent=$record['sms_sent_count'];
                $delivery=$record['current_delivery_id'];
                $digest=$record['sms_code_hmac'];
                if($sent>=1 && $sent<=ChallengeRules::MAX_SMS_SENDS_PER_CHALLENGE
                    && is_string($delivery)
                    && preg_match('/\A[a-f0-9]{64}\z/D',$delivery)===1
                    && is_string($digest)
                    && preg_match('/\A[a-f0-9]{64}\z/D',$digest)===1
                    && is_string($record['verification_stamp'])
                ) {
                    // Only a positive provider-confirmed 'sent' is acceptable.
                    // queued/claimed/failed/unknown cannot validate a code.
                    $out=$this->execute('SELECT status,verification_stamp,send_ordinal '
                        .'FROM ev_v5_sms_outbox WHERE delivery_id = ? '
                        .'AND challenge_id = ? FOR UPDATE',[$delivery,$challengeId]);
                    $outbox=$out->fetch(PDO::FETCH_ASSOC);
                    $expected=hash_hmac('sha256',
                        "evrasia:v5:sms-code:v1\0"
                        .$challengeId."\0".$delivery."\0".$code,
                        $this->smsCodeHmacKey);
                    $valid=is_array($outbox)
                        && ($outbox['status']??null)==='sent'
                        && ($outbox['verification_stamp']??null)===$record['verification_stamp']
                        && self::integer($outbox['send_ordinal']??null)===$sent
                        && hash_equals($digest,$expected);
                }
            } else {
                // Never accept TOTP based solely on a client value: native
                // Bitrix validates time step AND replay state under this lock.
                $valid=$record['sms_sent_count']===0
                    && $record['verification_stamp']===null
                    && $record['sms_code_hmac']===null
                    && $record['current_delivery_id']===null
                    && $this->native->consumeNativeTotpWithLocks(
                        $this->db,$record['user_id'],$code
                    ) === true;
            }

            if($valid) {
                $u=$this->execute('UPDATE ev_v5_auth_challenges '
                    .'SET status = ?, sms_code_hmac = NULL '
                    .'WHERE challenge_id = ? AND status = ? AND failed_attempts = ?',[
                    'consumed',$challengeId,'pending',$record['failed_attempts'],
                ]);
            } else {
                $attempts=$record['failed_attempts']+1;
                $u=$this->execute('UPDATE ev_v5_auth_challenges '
                    .'SET failed_attempts = ?, status = ? '
                    .'WHERE challenge_id = ? AND status = ? AND failed_attempts = ?',[
                    $attempts,$attempts>=ChallengeRules::MAX_FAILED_ATTEMPTS
                        ? 'cancelled':'pending',
                    $challengeId,'pending',$record['failed_attempts'],
                ]);
            }
            if($u->rowCount()!==1)
                throw new RuntimeException('v5_challenge_cas_failed');
            if(!$this->db->commit())
                throw new RuntimeException('v5_challenge_commit_failed');
            $started=false;
            if(!$valid) return null;
            // This is evidence ONLY of a consumed challenge; subsequent V5
            // session minting MUST reread it under b_user lock and current
            // AUTH_EPOCH. UNIQUE grant digest forbids minting twice.
            return new ConsumedLoginChallenge(
                $record['user_id'],$deviceId,$platform,$kind,$challengeId
            );
        } catch (Throwable) {
            if($started && $this->db->inTransaction()) {
                try{$this->db->rollBack();}catch(Throwable){}
            }
            throw new RuntimeException('v5_challenge_unavailable');
        }
    }

    private function assertReady():void {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()) {
            throw new RuntimeException('v5_challenge_connection_unavailable');
        }
        $q=$this->prepare('SELECT ENGINE FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        foreach(self::TABLES as $table) {
            if(!$q->execute([$table])||$q->fetchColumn()!=='InnoDB')
                throw new RuntimeException('v5_challenge_table_unavailable');
        }
    }

    private function dbNow():int {
        $q=$this->execute('SELECT UNIX_TIMESTAMP()',[]);
        $now=self::integer($q->fetchColumn());
        if($now===null || $now<=0 || $now>PHP_INT_MAX-300)
            throw new RuntimeException('v5_challenge_clock_unavailable');
        return $now;
    }

    /** @return array<string,mixed>|null */
    private static function record(mixed $row):?array {
        if(!is_array($row))return null;
        foreach(['user_id','issued_at','expires_at','failed_attempts','sms_sent_count']
            as $key) {
            $int=self::integer($row[$key]??null);
            if($int===null)return null;
            $row[$key]=$int;
        }
        if($row['user_id']<1
            || !is_string($row['challenge_id']??null)
            || preg_match('/\A[a-f0-9]{64}\z/D',$row['challenge_id'])!==1
            || !in_array($row['kind']??null,['sms','totp'],true)
            || !is_string($row['auth_epoch']??null)
            || preg_match('/\A[a-f0-9]{64}\z/D',$row['auth_epoch'])!==1
            || !(($row['verification_stamp']??null)===null
                || (is_string($row['verification_stamp'])
                    && preg_match('/\A[a-f0-9]{64}\z/D',$row['verification_stamp'])===1))
        ) return null;
        $row['password_proven']=($row['password_proven']??null)==='Y';
        return $row;
    }

    private static function integer(mixed $value):?int {
        if(is_int($value))return $value>=0?$value:null;
        if(!is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$value)!==1)return null;
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false?null:$n;
    }

    private function execute(string $sql,array $params):PDOStatement {
        $q=$this->prepare($sql);
        if(!$q->execute($params))throw new RuntimeException('v5_challenge_query_failed');
        return $q;
    }
    private function prepare(string $sql):PDOStatement {
        $q=$this->db->prepare($sql);
        if(!$q instanceof PDOStatement)throw new RuntimeException('v5_challenge_query_failed');
        return $q;
    }
}
