<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlEmailOtp.php';

use PDO;
use PDOStatement;
use Throwable;

/** Mandatory independent replay-resistant EMAIL grant proof for JWT minting. */
interface V5LockedConsumedEmailGrantProofPort
{
    public function verifiedConsumedEmailWithUserLock(
        PDO $db, ConsumedLoginChallenge $grant
    ): bool;
}

/**
 * Feature-only. The caller must own the SAME SQL transaction as the strong
 * session writer, with b_user locked FIRST. No side effect other than SELECT.
 * Unknown native MFA, changed email, epoch or missing ledger => DENY.
 */
final readonly class V5MySqlConsumedEmailGrantProof
    implements V5LockedConsumedEmailGrantProofPort
{
    public function __construct(
        private PDO $connection,
        private V5LockedEmailOtpProofPort $native,
        #[\SensitiveParameter] private string $bindingKey,
    ) {
        if (strlen($bindingKey)<32 || strlen($bindingKey)>1024)
            throw new \InvalidArgumentException('v5_email_grant_key_missing');
    }

    public function verifiedConsumedEmailWithUserLock(
        PDO $db, ConsumedLoginChallenge $grant
    ): bool {
        if ($db!==$this->connection || !$db->inTransaction()
            || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $grant->kind!==ChallengeKind::EMAIL || $grant->userId<1
            || !V5LoginWorkflow::validChallengeId($grant->grantReference)
            || !V5LoginWorkflow::validInstallation($grant->deviceId,$grant->platform))
            return false;
        try {
            $user=$this->q($db,'SELECT ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED '
                .'FROM b_user WHERE ID=? FOR UPDATE',[$grant->userId])->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user) || self::int($user['ID']??null)!==$grant->userId
                || ($user['ACTIVE']??null)!=='Y'
                || ($user['BLOCKED']??null)!=='N'
                || ($user['PASSWORD_EXPIRED']??null)!=='N') return false;
            $epoch=self::int($this->q($db,'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
                .'WHERE USER_ID=? FOR UPDATE',[$grant->userId])->fetchColumn());
            if ($epoch===null || $epoch<1) return false;
            $r=$this->q($db,'SELECT challenge_id,user_id,device_binding,platform,'
                .'status,email_stamp,auth_epoch,code_hmac,issued_at,expires_at,'
                .'failed_attempts FROM ev_v5_email_auth_challenges '
                .'WHERE challenge_id=? FOR UPDATE',[$grant->grantReference])
                ->fetch(PDO::FETCH_ASSOC);
            if (!is_array($r)) return false;
            $now=self::int($this->q($db,'SELECT UNIX_TIMESTAMP()',[])->fetchColumn());
            $issued=self::int($r['issued_at']??null);
            $expires=self::int($r['expires_at']??null);
            $attempts=self::int($r['failed_attempts']??null);
            $stamp=$r['email_stamp']??null;
            $binding=V5MySqlSmsOutbox::installationBinding(
                $grant->deviceId,$grant->platform,$this->bindingKey
            );
            if ($now===null || $issued===null || $expires===null
                || $attempts===null || $issued<1 || $issued>$now
                || $expires<=$now || $expires<=$issued || $expires-$issued>300
                || $attempts>=3
                || ($r['challenge_id']??null)!==$grant->grantReference
                || self::int($r['user_id']??null)!==$grant->userId
                || ($r['status']??null)!=='consumed'
                || ($r['platform']??null)!==$grant->platform
                || !is_string($r['device_binding']??null)
                || !hash_equals($r['device_binding'],$binding)
                || self::int($r['auth_epoch']??null)!==$epoch
                || !is_string($stamp)
                || preg_match('/\A[a-f0-9]{64}\z/D',$stamp)!==1
                || ($r['code_hmac']??null)!==null) return false;

            // Original SAVEPOINT continuity is checked by the enclosing
            // strong-session store. A valid client code by itself is NOT proof.
            return $this->native->stillCurrentWithLocks(
                $db,$grant->userId,$epoch,$stamp
            )===true;
        } catch (Throwable) {
            return false;
        }
    }

    private function q(PDO $db,string $sql,array $params):PDOStatement
    {
        $q=$db->prepare($sql);
        if (!$q instanceof PDOStatement || !$q->execute($params))
            throw new \RuntimeException('v5_email_grant_sql_unavailable');
        return $q;
    }

    private static function int(mixed $v):?int
    {
        if (is_int($v)) return $v>=0?$v:null;
        if (!is_string($v) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)
            return null;
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false?null:$n;
    }
}
