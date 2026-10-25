<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5EmailToSmsTransitionPolicy.php';
require_once __DIR__ . '/V5MySqlEmailOtp.php';

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Native Bitrix provider: must lock/recheck current native MFA (TOTP OFF),
 * user status, verified email + phone, complete security-hook coverage,
 * and native auth-epoch HMAC, on the exact caller-owned transaction.
 *
 * This is a trusted installed server implementation, NEVER client data.
 * Must not send a message, create another transaction, impersonate a
 * browser session, or commit or roll back the caller's transaction.
 */
interface V5LockedEmailToSmsStageProofPort
{
    public function currentFactsWithUserLock(
        PDO $db, int $userId, array $lockedUser,
        string $deviceId, string $platform
    ): ?V5LockedEmailToSmsStageFacts;
}

final readonly class V5LockedEmailToSmsStageFacts
{
    public function __construct(
        public int $numericAuthEpoch,
        public string $emailStamp,
        public string $smsAuthEpochStamp,
    ) {
        if ($numericAuthEpoch < 1
            || preg_match('/\A[a-f0-9]{64}\z/D',$emailStamp)!==1
            || preg_match('/\A[a-f0-9]{64}\z/D',$smsAuthEpochStamp)!==1) {
            throw new RuntimeException('v5_sms_stage_native_facts_unavailable');
        }
    }
}

/**
 * Not a pending mobile challenge or permission to send an SMS.
 * Only the private site-owned dispatcher with independently attested
 * paid-provider receipt may convert a staged row into a visible ticket.
 */
final readonly class V5CommittedEmailSmsStage
{
    public function __construct(
        public string $smsChallengeId,
        public int $userId,
        public int $expiresAt,
    ) {}
}

/**
 * Real V5 SQL staging candidate, still NOT installed in production.
 *
 * Lock order: b_user -> native MFA/phone via same-transaction proof ->
 * ev_samzaberu_auth_state -> accepted email row.
 * Caller must independently attest that this order matches actual native
 * site writers before deployment. A native callback is protected by an
 * owned SAVEPOINT to detect hidden COMMIT + BEGIN.
 *
 * Stage new SMS row and cancel email in ONE SQL transaction; pay for SMS
 * only AFTER a positively committed stage, using separately approved
 * existing site transport/budgets/receipt. No new SMS generation here.
 *
 * An unknown COMMIT outcome yields NO paid-send permit, no retry and no
 * success response. A retry will be rejected if the first COMMIT succeeded.
 */
final class V5EmailToSmsAtomicStage
{
    public function __construct(
        private PDO $db,
        private V5LockedEmailToSmsStageProofPort $native,
        #[\SensitiveParameter] private string $deviceBindingKey,
    ) {
        if (strlen($deviceBindingKey)<32) {
            throw new RuntimeException('v5_sms_stage_binding_unavailable');
        }
    }

    public function stage(
        string $emailChallengeId, string $deviceId, string $platform
    ): ?V5CommittedEmailSmsStage {
        if (!V5LoginWorkflow::validChallengeId($emailChallengeId)
            || !V5LoginWorkflow::validInstallation($deviceId,$platform)) {
            return null;
        }
        $this->assertReady();
        $started=false;
        $guard=false;
        $releaseAttempted=false;
        try {
            // Point read is only a user lock-order hint, NEVER admission.
            $hint=$this->q('SELECT user_id FROM ev_v5_email_auth_challenges '
                .'WHERE challenge_id = ? LIMIT 1',[$emailChallengeId])
                ->fetch(PDO::FETCH_ASSOC);
            $uid=self::positive($hint['user_id']??null);
            if ($uid===null) return null;

            if (!$this->db->beginTransaction()) {
                throw new RuntimeException('v5_sms_stage_begin');
            }
            $started=true;
            $user=$this->q('SELECT ID,ACTIVE,BLOCKED,PASSWORD_EXPIRED,PASSWORD '
                .'FROM b_user WHERE ID = ? FOR UPDATE',[$uid])
                ->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)
                || self::positive($user['ID']??null)!==$uid
                || ($user['ACTIVE']??null)!=='Y'
                || ($user['BLOCKED']??null)!=='N'
                || ($user['PASSWORD_EXPIRED']??null)!=='N'
                || !is_string($user['PASSWORD']??null)
                || $user['PASSWORD']==='') {
                throw new RuntimeException('v5_sms_stage_account_denied');
            }

            // The native callback MUST neither send SMS nor change
            // transaction ownership. RELEASE proves savepoint continuity.
            $this->q('SAVEPOINT v5_email_sms_stage_guard',[]);
            $guard=true;
            $facts=$this->native->currentFactsWithUserLock(
                $this->db,$uid,$user,$deviceId,$platform
            );
            $releaseAttempted=true;
            $this->q('RELEASE SAVEPOINT v5_email_sms_stage_guard',[]);
            $guard=false;
            if (!$this->db->inTransaction()
                || !$facts instanceof V5LockedEmailToSmsStageFacts) {
                throw new RuntimeException('v5_sms_stage_native_denied');
            }

            $state=$this->q('SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
                .'WHERE USER_ID = ? FOR UPDATE',[$uid])->fetch(PDO::FETCH_ASSOC);
            $epoch=self::positive($state['AUTH_EPOCH']??null);
            if ($epoch===null || $epoch!==$facts->numericAuthEpoch) {
                throw new RuntimeException('v5_sms_stage_epoch_changed');
            }
            $row=$this->q('SELECT challenge_id,user_id,device_binding,platform,status,'
                .'email_stamp,auth_epoch,code_hmac,issued_at,expires_at,failed_attempts '
                .'FROM ev_v5_email_auth_challenges WHERE challenge_id = ? FOR UPDATE',
                [$emailChallengeId])->fetch(PDO::FETCH_ASSOC);
            $now=self::positive($this->q('SELECT UNIX_TIMESTAMP()',[])->fetchColumn());
            if ($now===null || !is_array($row)) {
                throw new RuntimeException('v5_sms_stage_clock_or_row');
            }
            $binding=V5MySqlSmsOutbox::installationBinding(
                $deviceId,$platform,$this->deviceBindingKey
            );
            if (!V5EmailToSmsTransitionPolicy::emailEligible(
                $row,$emailChallengeId,$uid,$binding,$platform,$epoch,
                $facts->emailStamp,$now
            )) {
                throw new RuntimeException('v5_sms_stage_email_unavailable');
            }
            $expires=min($now+300,(int)$row['expires_at']);
            if ($expires<=$now) {
                throw new RuntimeException('v5_sms_stage_expired');
            }
            $smsId=bin2hex(random_bytes(32));
            $staged=[
                'challenge_id'=>$smsId,'user_id'=>$uid,
                'device_binding'=>$binding,'platform'=>$platform,
                'kind'=>'sms','status'=>'pending','password_proven'=>'Y',
                'auth_epoch'=>$facts->smsAuthEpochStamp,
                'issued_at'=>$now,'expires_at'=>$expires,
                'failed_attempts'=>0,'sms_sent_count'=>0,
                'sms_code_hmac'=>null,'last_sms_sent_at'=>null,
                'current_delivery_id'=>null,'verification_stamp'=>null,
            ];
            if (!V5EmailToSmsTransitionPolicy::stagedSmsEligible(
                $staged,$emailChallengeId,$smsId,$uid,$binding,$platform,
                $facts->smsAuthEpochStamp,$now
            )) throw new RuntimeException('v5_sms_stage_invalid_new_row');

            $insert=$this->q('INSERT INTO ev_v5_auth_challenges '
                .'(challenge_id,user_id,device_binding,platform,kind,status,'
                .'password_proven,issued_at,expires_at,failed_attempts,'
                .'sms_sent_count,last_sms_sent_at,verification_stamp,'
                .'auth_epoch,sms_code_hmac,current_delivery_id) '
                .'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
                    $smsId,$uid,$binding,$platform,'sms','pending','Y',
                    $now,$expires,0,0,null,null,$facts->smsAuthEpochStamp,
                    null,null
                ]);
            if ($insert->rowCount()!==1) {
                throw new RuntimeException('v5_sms_stage_insert_failed');
            }
            $cas=$this->q(V5EmailToSmsTransitionPolicy::CANCEL_EMAIL_CAS,[
                'cancelled',$emailChallengeId,$uid,'accepted',$epoch,
                $facts->emailStamp,$now
            ]);
            if ($cas->rowCount()!==1 || !$this->db->inTransaction()
                || !$this->db->commit()) {
                throw new RuntimeException('v5_sms_stage_commit_failed');
            }
            $started=false;
            return new V5CommittedEmailSmsStage($smsId,$uid,$expires);
        } catch (Throwable) {
            if ($started) {
                if ($guard) {
                    if (!$releaseAttempted) {
                        try {
                            $this->q('RELEASE SAVEPOINT v5_email_sms_stage_guard',[]);
                            $guard=false;
                        } catch (Throwable) {}
                    }
                    if ($guard) {
                        if ($this->db instanceof V5BitrixPdoCompatibilityBridge) {
                            $this->db->abandonUncertainNativeTransaction();
                        }
                        return null;
                    }
                }
                if ($this->db->inTransaction()) {
                    try { $this->db->rollBack(); } catch (Throwable) {}
                }
            }
            return null;
        }
    }

    private function assertReady(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || $this->db->getAttribute(PDO::ATTR_ERRMODE)!==PDO::ERRMODE_EXCEPTION
            || $this->db->inTransaction()) {
            throw new RuntimeException('v5_sms_stage_connection_denied');
        }
        $q=$this->db->prepare('SELECT ENGINE FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
        if (!$q instanceof PDOStatement) {
            throw new RuntimeException('v5_sms_stage_tables_unavailable');
        }
        foreach (['b_user','ev_samzaberu_auth_state',
            'ev_v5_email_auth_challenges','ev_v5_auth_challenges'] as $table) {
            if (!$q->execute([$table]) || $q->fetchColumn()!=='InnoDB') {
                throw new RuntimeException('v5_sms_stage_tables_unavailable');
            }
        }
    }

    private static function positive(mixed $value): ?int
    {
        if (is_int($value)) return $value>0?$value:null;
        if (!is_string($value)
            || preg_match('/\A[1-9][0-9]*\z/D',$value)!==1) return null;
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $n===false?null:$n;
    }

    private function q(string $sql,array $args): PDOStatement
    {
        $s=$this->db->prepare($sql);
        if (!$s instanceof PDOStatement || !$s->execute($args)) {
            throw new RuntimeException('v5_sms_stage_sql_unavailable');
        }
        return $s;
    }
}
