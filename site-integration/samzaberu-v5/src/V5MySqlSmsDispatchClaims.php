<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlSmsOutbox.php';

use PDO;
use PDOStatement;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Verified SITE dispatch proof, mandatory and NEVER provided by default.
 * Recheck active/unblocked account, native TOTP-off policy, unchanged
 * AUTH_EPOCH and current b_user_phone_auth CONFIRMED/E164/version stamp
 * with the SAME locked DB transaction. MUST also verify the latest genuine
 * provider tariff is within authorizedMaxPriceMinor and currency matches,
 * to avoid charging more after the original reservation. The future sender
 * MUST make a final provider-quote/phone check immediately before send.
 * Must recheck before each paid send.
 * Any unknown state is false. Do not log phone, code or decrypted SMS text.
 */
interface V5LockedSmsDispatchSafetyPort
{
    public function stillSafeWithChallengeLocks(
        PDO $db,int $userId,string $challengeEpoch,string $verificationStamp,
        int $authorizedMaxPriceMinor,string $currency
    ): bool;
}

/**
 * Mandatory authoritative paid-provider receipt verification for status SENT.
 * A persisted idempotency key by itself is NOT a delivery acknowledgement.
 * Implementations must independently attest the provider result (not trust
 * caller claims), match the exact delivery and charge identity, and deny
 * unknown/missing evidence. No real provider is installed by default.
 */
interface V5SmsSentReceiptAttestationPort
{
    public function independentlyConfirmedSent(
        string $deliveryId,
        string $providerIdempotencyKey,
    ): bool;
}

enum V5SmsProviderTerminalOutcome:string
{
    case Sent='sent';
    case Failed='failed';
    case Unknown='unknown';
}

/**
 * Ciphertext, NOT plain SMS text. A future site-specific dispatcher must
 * perform its final native phone recheck before invoking the provider and
 * must use the stable providerIdempotencyKey. Never automatically retry a
 * claimed or unknown delivery after an uncertain paid provider outcome.
 */
final readonly class V5ClaimedSmsForOneAttempt
{
    public function __construct(
        public string $deliveryId,
        public string $providerIdempotencyKey,
        #[\SensitiveParameter] public string $encryptedEnvelope,
    ){
        if(!V5LoginWorkflow::validChallengeId($deliveryId)
            || !V5LoginWorkflow::validChallengeId($providerIdempotencyKey)
            || !hash_equals($deliveryId,$providerIdempotencyKey)
            || $encryptedEnvelope==='' || strlen($encryptedEnvelope)>8192)
            throw new InvalidArgumentException('Invalid claimed SMS delivery');
    }
}

/**
 * DB-ONLY transition protocol: queued -> claimed -> sent/failed/unknown.
 * This class NEVER decrypts, contacts an SMS provider, or retries a paid send.
 *
 * Lock order for worker claim: challenge row FIRST, matching outbox row,
 * THEN site-specific native safety locks. Do not hold a transaction while
 * calling the paid provider. There is intentionally no stale-claim reaper:
 * uncertain provider outcome must be reconciled, never auto-retried.
 */
final readonly class V5MySqlSmsDispatchClaims
{
    private const REQUIRED_TABLES=[
        'b_user','ev_samzaberu_auth_state',
        'ev_v5_auth_challenges','ev_v5_sms_outbox',
    ];

    public function __construct(
        private PDO $db,
        private V5LockedSmsDispatchSafetyPort $native,
        private ?V5SmsSentReceiptAttestationPort $sentReceipt = null,
    ) {}

    public function claimQueuedOnce(string $deliveryId): ?V5ClaimedSmsForOneAttempt
    {
        if(!V5LoginWorkflow::validChallengeId($deliveryId))return null;
        $this->assertReady();

        // Unlocked, read-only hint. The exact challenge/outbox identities
        // and state MUST be reread and locked inside the transaction.
        $hint=$this->query('SELECT challenge_id,user_id '
            .'FROM ev_v5_sms_outbox WHERE delivery_id = ? LIMIT 2',[$deliveryId])
            ->fetch(PDO::FETCH_ASSOC);
        if(!is_array($hint)
            || !self::hex64($hint['challenge_id']??null)
            || self::positive($hint['user_id']??null)===null)return null;
        $cid=$hint['challenge_id'];
        $userId=self::positive($hint['user_id']);
        $started=false;
        try{
            if(!$this->db->beginTransaction())
                throw new RuntimeException('v5_sms_claim_tx_unavailable');
            $started=true;
            $now=self::positive($this->query('SELECT UNIX_TIMESTAMP()',[])
                ->fetchColumn());
            if($now===null)throw new RuntimeException('v5_sms_clock_unavailable');
            $q=$this->query('SELECT challenge_id,user_id,device_binding,'
                .'platform,kind,status,password_proven,issued_at,expires_at,'
                .'failed_attempts,sms_sent_count,verification_stamp,'
                .'auth_epoch,current_delivery_id '
                .'FROM ev_v5_auth_challenges WHERE challenge_id = ? '
                .'FOR UPDATE',[$cid]);
            $challenge=$q->fetch(PDO::FETCH_ASSOC);
            $q=$this->query('SELECT delivery_id,challenge_id,user_id,'
                .'send_ordinal,encrypted_envelope,provider_idempotency_key,'
                .'verification_stamp,max_price_minor,currency,status '
                .'FROM ev_v5_sms_outbox WHERE delivery_id = ? FOR UPDATE',
                [$deliveryId]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!self::validPendingDelivery(
                $challenge,$row,$cid,$deliveryId,$userId,$now
            )){
                $this->db->rollBack();$started=false;
                return null;
            }
            $safe=$this->native->stillSafeWithChallengeLocks(
                $this->db,$userId,
                $challenge['auth_epoch'],$challenge['verification_stamp'],
                self::positive($row['max_price_minor']),$row['currency']
            );
            if($safe!==true){
                $this->db->rollBack();$started=false;
                return null;
            }
            $u=$this->query('UPDATE ev_v5_sms_outbox SET status = ? '
                .'WHERE delivery_id = ? AND challenge_id = ? '
                .'AND provider_idempotency_key = ? AND status = ?',[
                'claimed',$deliveryId,$cid,
                $row['provider_idempotency_key'],'queued',
            ]);
            if($u->rowCount()!==1)
                throw new RuntimeException('v5_sms_claim_cas_failed');
            if(!$this->db->commit())
                throw new RuntimeException('v5_sms_claim_uncertain_commit');
            $started=false;
            return new V5ClaimedSmsForOneAttempt(
                $deliveryId,$row['provider_idempotency_key'],
                $row['encrypted_envelope']
            );
        }catch(Throwable){
            if($started && $this->db->inTransaction()){
                try{$this->db->rollBack();}catch(Throwable){}
            }
            throw new RuntimeException('v5_sms_claim_unavailable');
        }
    }

    /**
     * Must be called ONLY by the future attested, server-side paid-provider
     * worker after obtaining the provider's authoritative result. A timeout,
     * connection reset, ambiguous response or uncertain charge is UNKNOWN.
     * Unknown and claimed states cannot be automatically retried.
     */
    public function recordProviderOutcome(
        string $deliveryId,
        string $providerIdempotencyKey,
        V5SmsProviderTerminalOutcome $outcome,
    ):bool {
        if(!V5LoginWorkflow::validChallengeId($deliveryId)
            || !V5LoginWorkflow::validChallengeId($providerIdempotencyKey)
            || !hash_equals($deliveryId,$providerIdempotencyKey))return false;
        $this->assertReady();
        if ($outcome === V5SmsProviderTerminalOutcome::Sent) {
            // Fail closed BEFORE persisting a paid-SMS success or allowing
            // its six-digit code to authenticate an account.
            if ($this->sentReceipt === null) return false;
            try {
                if ($this->sentReceipt->independentlyConfirmedSent(
                    $deliveryId,$providerIdempotencyKey
                ) !== true) return false;
            } catch (Throwable) {
                return false;
            }
        }
        try{
            $q=$this->query('UPDATE ev_v5_sms_outbox SET status = ? '
                .'WHERE delivery_id = ? AND provider_idempotency_key = ? '
                .'AND status = ?',[
                $outcome->value,$deliveryId,$providerIdempotencyKey,'claimed',
            ]);
            return $q->rowCount()===1;
        }catch(Throwable){
            throw new RuntimeException('v5_sms_provider_ack_unavailable');
        }
    }

    private static function validPendingDelivery(
        mixed $challenge,mixed $row,string $cid,string $did,int $userId,int $now
    ):bool {
        if(!is_array($challenge)||!is_array($row)
            || ($challenge['challenge_id']??null)!==$cid
            || ($row['challenge_id']??null)!==$cid
            || ($row['delivery_id']??null)!==$did
            || self::positive($challenge['user_id']??null)!==$userId
            || self::positive($row['user_id']??null)!==$userId
            || ($challenge['kind']??null)!=='sms'
            || ($challenge['status']??null)!=='pending'
            || ($challenge['password_proven']??null)!=='Y'
            || ($row['status']??null)!=='queued'
            || ($challenge['current_delivery_id']??null)!==$did
            || ($challenge['platform']??null)!=='ios'
                && ($challenge['platform']??null)!=='android'
            || !self::hex64($challenge['device_binding']??null)
            || !self::hex64($challenge['verification_stamp']??null)
            || !self::hex64($challenge['auth_epoch']??null)
            || !is_string($row['verification_stamp']??null)
            || !hash_equals($challenge['verification_stamp'],$row['verification_stamp'])
            || !is_string($row['provider_idempotency_key']??null)
            || !hash_equals($did,$row['provider_idempotency_key'])
            || !is_string($row['encrypted_envelope']??null)
            || $row['encrypted_envelope']===''
            || strlen($row['encrypted_envelope'])>8192
            || preg_match('/\A[A-Z]{3}\z/D',$row['currency']??'')!==1
            || self::positive($row['max_price_minor']??null)===null
            || self::positive($row['max_price_minor'])>1000000000)return false;

        $issued=self::positive($challenge['issued_at']??null);
        $expires=self::positive($challenge['expires_at']??null);
        $failed=self::nonnegative($challenge['failed_attempts']??null);
        $sent=self::positive($challenge['sms_sent_count']??null);
        $ordinal=self::positive($row['send_ordinal']??null);
        return $issued!==null && $issued<=$now && $expires!==null
            && $expires>$now && $expires>$issued
            && $expires-$issued<=ChallengeRules::MAX_TTL_SECONDS
            && $failed!==null && $failed<ChallengeRules::MAX_FAILED_ATTEMPTS
            && $sent!==null && $sent<=ChallengeRules::MAX_SMS_SENDS_PER_CHALLENGE
            && $ordinal===$sent;
    }

    private function assertReady():void {
        if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction())
            throw new RuntimeException('v5_sms_claim_connection_unavailable');
        $q=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if(!$q instanceof PDOStatement)
            throw new RuntimeException('v5_sms_claim_tables_unavailable');
        foreach(self::REQUIRED_TABLES as $name){
            if(!$q->execute([$name]) || $q->fetchColumn()!=='InnoDB')
                throw new RuntimeException('v5_sms_claim_tables_unavailable');
        }
    }

    private function query(string $sql,array $args):PDOStatement {
        $q=$this->db->prepare($sql);
        if(!$q instanceof PDOStatement || !$q->execute($args))
            throw new RuntimeException('v5_sms_claim_sql_unavailable');
        return $q;
    }

    private static function hex64(mixed $v):bool {
        return is_string($v) && preg_match('/\A[a-f0-9]{64}\z/D',$v)===1;
    }
    private static function positive(mixed $v):?int {
        $n=self::nonnegative($v);return $n!==null&&$n>0?$n:null;
    }
    private static function nonnegative(mixed $v):?int {
        if(is_int($v))return $v>=0?$v:null;
        if(!is_string($v) || preg_match('/\A(?:0|[1-9][0-9]*)\z/D',$v)!==1)
            return null;
        $n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        return $n===false?null:$n;
    }
}
