<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlStrongSessionStore.php';
require_once __DIR__ . '/V5MySqlChallengeConsumption.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Fail-closed bridge from durable, one-time consumed SMS/TOTP challenge to
 * strong V5 session issuance. Executed inside V5MySqlStrongSessionStore's
 * exclusive b_user-first transaction BEFORE signed access JWT is created.
 *
 * REQUIRES an independently implemented trusted-device port and NATIVE
 * account/MFA/verified-phone AUTH_EPOCH recheck port. Does NOT itself verify
 * native TOTP, issue SMS, create a session or authorize production routes.
 *
 * The UNIQUE (grant_digest) in ev_v5_strong_sessions prevents two sessions
 * being minted from the same challenge across parallel requests.
 */
final readonly class V5MySqlConsumedChallengeProof implements V5LockedStrongSessionProofPort
{
    public function __construct(
        private V5LockedStrongSessionProofPort $trustedDeviceProof,
        private V5LockedChallengeNativeFactorPort $native,
        #[\SensitiveParameter] private string $bindingKey,
    ) {
        if(strlen($bindingKey)<32)
            throw new InvalidArgumentException('V5 consumed grant binding key unavailable');
    }

    public function verifiedTrustWithUserLock(
        PDO $db,PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken
    ):bool {
        return $db->inTransaction()
            && $this->trustedDeviceProof->verifiedTrustWithUserLock(
                $db,$account,$deviceId,$platform,$trustToken
            )===true;
    }

    public function verifiedConsumedGrantWithUserLock(PDO $db,ConsumedLoginChallenge $grant):bool
    {
        if(!$db->inTransaction()
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION)
            return false;

        try{
            $q=$this->execute($db,'SELECT user_id,device_binding,platform,kind,'
                .'status,password_proven,issued_at,expires_at,failed_attempts,'
                .'sms_sent_count,verification_stamp,auth_epoch,sms_code_hmac,'
                .'current_delivery_id FROM ev_v5_auth_challenges '
                .'WHERE challenge_id = ? FOR UPDATE',[$grant->grantReference]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($row))return false;
            $user=self::num($row['user_id']??null);
            $issued=self::num($row['issued_at']??null);
            $expires=self::num($row['expires_at']??null);
            $attempts=self::num($row['failed_attempts']??null);
            $sent=self::num($row['sms_sent_count']??null);
            $dbNow=$this->execute($db,'SELECT UNIX_TIMESTAMP()',[])->fetchColumn();
            $now=self::num($dbNow);
            $epoch=$row['auth_epoch']??null;
            $stamp=$row['verification_stamp']??null;
            $expected=V5MySqlSmsOutbox::installationBinding(
                $grant->deviceId,$grant->platform,$this->bindingKey
            );
            if($now===null || $user!==$grant->userId
                || $issued===null || $expires===null || $attempts===null || $sent===null
                || $issued<1 || $issued>$now || $expires<=$now
                || $expires<=$issued || $expires-$issued>ChallengeRules::MAX_TTL_SECONDS
                || $attempts>=ChallengeRules::MAX_FAILED_ATTEMPTS
                || $row['status']!=='consumed'
                || ($row['password_proven']??null)!=='Y'
                || ($row['kind']??null)!==$grant->kind->value
                || ($row['platform']??null)!==$grant->platform
                || !is_string($row['device_binding']??null)
                || preg_match('/\A[a-f0-9]{64}\z/D',$row['device_binding'])!==1
                || !hash_equals($row['device_binding'],$expected)
                || !is_string($epoch)
                || preg_match('/\A[a-f0-9]{64}\z/D',$epoch)!==1
                || ($row['sms_code_hmac']??null)!==null) return false;

            if($grant->kind===ChallengeKind::SMS){
                $delivery=$row['current_delivery_id']??null;
                if($sent<1 || $sent>ChallengeRules::MAX_SMS_SENDS_PER_CHALLENGE
                    || !is_string($stamp)
                    || preg_match('/\A[a-f0-9]{64}\z/D',$stamp)!==1
                    || !is_string($delivery)
                    || preg_match('/\A[a-f0-9]{64}\z/D',$delivery)!==1) return false;
                $d=$this->execute($db,'SELECT status,verification_stamp,send_ordinal '
                    .'FROM ev_v5_sms_outbox WHERE delivery_id = ? '
                    .'AND challenge_id = ? FOR UPDATE',[
                    $delivery,$grant->grantReference
                ])->fetch(PDO::FETCH_ASSOC);
                if(!is_array($d)||($d['status']??null)!=='sent'
                    ||($d['verification_stamp']??null)!==$stamp
                    ||self::num($d['send_ordinal']??null)!==$sent)return false;
            } else {
                if($sent!==0 || $stamp!==null
                    ||($row['current_delivery_id']??null)!==null)return false;
            }

            // Recheck AGAIN before issuing the V5 JWT, under the session
            // transaction's b_user row lock. The site-specific implementation
            // must compare the SAME shared AUTH_EPOCH, native MFA and phone
            // verification stamp, not trust this stored challenge snapshot.
            return $this->native->currentFactorEligibleWithLocks(
                $db,$grant->userId,$grant->kind,$epoch,$stamp
            )===true;
        }catch(\Throwable){
            return false; // Fail closed, never expose native SQL/verification details.
        }
    }

    private function execute(PDO $db,string $sql,array $params):PDOStatement {
        $q=$db->prepare($sql);
        if(!$q instanceof PDOStatement||!$q->execute($params))
            throw new RuntimeException('v5_grant_read_unavailable');
        return $q;
    }
    private static function num(mixed $n):?int {
        if(is_int($n))return $n>=0?$n:null;
        if(!is_string($n)
            ||preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$n)!==1)return null;
        $x=filter_var($n,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $x===false?null:$x;
    }
}
