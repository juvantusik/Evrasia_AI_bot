<?php
declare(strict_types=1);

// No accounts, live PDO, Bitrix startup, SMS, Redis or filesystem writes.
// Contract checks only + a denial-only PDO stub (never executes SQL).
require_once __DIR__ . '/../src/V5EmailToSmsAtomicStage.php';

use Evrasia\Samzaberu\V5\{
    V5BitrixPdoCompatibilityBridge,V5EmailToSmsAtomicStage,
    V5EmailToSmsTransitionPolicy,V5LockedEmailToSmsStageFacts,
    V5LockedEmailToSmsStageProofPort
};
$checks=0;
$check=static function(bool $yes,string $label)use(&$checks):void{
    if(!$yes)throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$root=dirname(__DIR__);
$source=file_get_contents($root.'/src/V5EmailToSmsAtomicStage.php');
$bridgeSource=file_get_contents($root.'/src/V5BitrixPdoCompatibilityBridge.php');
if(!is_string($source)||!is_string($bridgeSource))throw new RuntimeException('missing source');
$check(str_contains($source,'final class V5EmailToSmsAtomicStage'),
    'real single-transaction stage class exists');
$check(str_contains($source,'$this->db->beginTransaction()')
    && str_contains($source,'$this->db->commit()')
    && str_contains($source,'$this->db->rollBack()'),
    'SQL stage owns transaction and fail-closed rollback');
$check(strpos($source,'FROM b_user WHERE ID = ? FOR UPDATE') <
    strpos($source,'FROM ev_v5_email_auth_challenges WHERE challenge_id = ? FOR UPDATE'),
    'b_user is locked before original email challenge');
$check(strpos($source,'INSERT INTO ev_v5_auth_challenges') <
    strpos($source,'V5EmailToSmsTransitionPolicy::CANCEL_EMAIL_CAS')
    && strpos($source,'V5EmailToSmsTransitionPolicy::CANCEL_EMAIL_CAS') <
       strpos($source,'$this->db->commit()'),
    'new SMS stage and email cancellation occur before one commit');
$check(str_contains($source,'code_hmac')
    && str_contains($source,'V5EmailToSmsTransitionPolicy::emailEligible(')
    && str_contains($source,'V5EmailToSmsTransitionPolicy::stagedSmsEligible('),
    'both challenge rows validated with shared policies');
$check(str_contains($source,'RELEASE SAVEPOINT v5_email_sms_stage_guard')
    && str_contains($source,'abandonUncertainNativeTransaction'),
    'native savepoint continuity loss quarantines bridge');
$check(!str_contains($source,'CSms::send(')
    && !str_contains($source,'$_SESSION')
    && !str_contains($source,'sendMessage(')
    && !str_contains($source,'deliverToVerifiedAccount(')
    && !str_contains($source,'acceptedForDelivery('),
    'stage code never calls paid sender or browser sessions');
$check(str_contains($source,'return new V5CommittedEmailSmsStage(')
    && !str_contains($source,'return new PendingLoginChallenge(')
    && !str_contains($source,'new AuthenticatedV5Session('),
    'stage cannot issue a pending SMS login ticket or JWT');
$allow=new ReflectionMethod(V5BitrixPdoCompatibilityBridge::class,'assertStatementAllowed');
$allow->invoke(null,V5EmailToSmsTransitionPolicy::CANCEL_EMAIL_CAS);
$allow->invoke(null,'SAVEPOINT v5_email_sms_stage_guard');
$allow->invoke(null,'RELEASE SAVEPOINT v5_email_sms_stage_guard');
$checks+=3;
foreach([
    V5EmailToSmsTransitionPolicy::CANCEL_EMAIL_CAS.' OR 1 = 1',
    'UPDATE ev_v5_email_auth_challenges SET status = ? WHERE user_id = ?',
    'SAVEPOINT v5_email_sms_other_guard',
    'DROP TABLE ev_v5_email_auth_challenges',
] as $sql) {
    try {$allow->invoke(null,$sql);}
    catch(RuntimeException) {$checks++;continue;}
    throw new RuntimeException('FAIL: permissive native SQL bridge');
}
$check(str_contains($bridgeSource,"'SAVEPOINT v5_email_sms_stage_guard'")
    && str_contains($bridgeSource,"'RELEASE SAVEPOINT v5_email_sms_stage_guard'"),
    'only exactly pinned stage continuity guards in native SQL bridge');

// No synthetic account or real DB fixture: the following fake refuses any
// prepare(). Its only purpose is testing no native proof or write when
// schema readiness is absent.
$fake=new class extends PDO {
    public int $queries=0;
    public function __construct(){}
    public function getAttribute(int $attribute):mixed {
        return match($attribute) {
            PDO::ATTR_DRIVER_NAME=>'mysql',
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            default=>null,
        };
    }
    public function inTransaction():bool {return false;}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $this->queries++; return false;
    }
};
$proof=new class implements V5LockedEmailToSmsStageProofPort {
    public int $calls=0;
    public function currentFactsWithUserLock(
        PDO $db,int $userId,array $lockedUser,string $deviceId,string $platform
    ):?V5LockedEmailToSmsStageFacts {
        $this->calls++;
        throw new RuntimeException('native must not run');
    }
};
$stage=new V5EmailToSmsAtomicStage($fake,$proof,str_repeat('k',40));
$check($stage->stage('wrong','sz_'.str_repeat('a',64),'ios')===null
    && $fake->queries===0,'invalid ID denied before SQL');
$check($stage->stage(str_repeat('b',64),'not-a-mobile-id','ios')===null
    && $fake->queries===0,'invalid installation denied before SQL');
try {$stage->stage(str_repeat('b',64),'sz_'.str_repeat('a',64),'ios');}
catch(RuntimeException $e) {
    $check($e->getMessage()==='v5_sms_stage_tables_unavailable',
        'absent table preflight denies without native proof');
}
$check($fake->queries===1 && $proof->calls===0,
    'schema unavailable cannot invoke native provider or SMS');

printf("PASS: V5 atomic email-to-SMS stage safety contract (%d assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nREAL_DATABASE_CONNECTION=NO\n";
echo "SYNTHETIC_ACCOUNTS=NO\nSQL_EXECUTED=NO\nSMS_SENT=NO\n";
echo "V5_PRODUCTION_AUTH=OFF\nFINAL_STATUS=PASS\n";
