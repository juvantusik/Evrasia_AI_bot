<?php
declare(strict_types=1);

/**
 * REAL InnoDB SMS budget/outbox acceptance in GITHUB EPHEMERAL CI only.
 * This test NEVER sends an SMS or opens any production database.
 * Uses synthetic phone, 10 synthetic quota buckets and encrypted outbox.
 * Site verification and delivery worker remain MANDATORY and UNIMPLEMENTED.
 */
if (getenv('GITHUB_ACTIONS')!=='true'
    || getenv('V5_CI_ONLY_ACK')!=='GITHUB_EPHEMERAL_MARIADB'
    || getenv('V5_CI_DB_HOST')!=='127.0.0.1'
    || getenv('V5_CI_DB_NAME')!=='ev_v5_ci_synthetic'
    || getenv('V5_CI_DB_USER')!=='ev_v5_ci'
    || in_array(gethostname(),['evrasia','eur-bot-01'],true)
    || !extension_loaded('pdo_mysql')
    || !extension_loaded('sodium')
    || !function_exists('pcntl_fork')) {
    fwrite(STDERR,"FAIL: ephemeral SMS outbox CI guard\n");
    exit(2);
}

require_once __DIR__ . '/../../src/V5MySqlSmsOutbox.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlSmsOutbox,V5SmsLockedEligibilityPort,V5SmsQueueReservation,
    V5VerifiedSmsDestination,V5SmsPriceCeiling,V5SmsBudgetWindow,
    V5SodiumSmsMessageFactory,V5SmsQueuedDelivery,AuthenticationRejected
};

const DEVICE_ID='sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SMS_CHALLENGE='cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
const SMS_PHONE='+79120000000'; // Synthetic test fixture, NOT user data.
const SMS_STAMP='ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';
const SMS_EPOCH='eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
const BIND_KEY='BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
const SMS_ENVELOPE_KEY='EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE';
const SMS_HMAC_KEY='HHHHHHHHHHHHHHHHHHHHHHHHHHHHHHHH';

$checks=0;
function smsCheck(bool $cond,string $label):void {
    global $checks;
    if(!$cond)throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function smsDeny(callable $cb,string $label):void {
    try{$cb();}catch(Throwable){smsCheck(true,$label);return;}
    smsCheck(false,$label);
}
function smsDb():PDO {
    $password=getenv('V5_CI_DB_PASSWORD');
    if(!is_string($password)||strlen($password)<16||strlen($password)>128)
        throw new RuntimeException('ephemeral DB credentials absent');
    $db=new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=ev_v5_ci_synthetic;charset=utf8mb4',
        'ev_v5_ci',$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT=>5,
        ]
    );
    if($db->query('SELECT DATABASE()')->fetchColumn()!=='ev_v5_ci_synthetic')
        throw new RuntimeException('ephemeral DB name mismatch');
    return $db;
}
function smsCount(PDO $db,string $table):int {
    if(!in_array($table,['ev_v5_sms_outbox','ev_v5_sms_budget_windows'],true))
        throw new RuntimeException('table guard');
    return (int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
}
final class CiSmsNativeEligibility implements V5SmsLockedEligibilityPort {
    public bool $allow=true;
    public function stillEligibleWithLocks(
        PDO $db,V5SmsQueueReservation $request,string $challengeAuthEpoch
    ):bool {
        // Fake site callback: same transaction/epoch/binding checks only.
        // This does NOT attest live native verified-phone/MFA handlers.
        return $db->inTransaction() && $this->allow
            && $request->userId===41
            && $request->destination->userId===41
            && $request->destination->e164===SMS_PHONE
            && $request->destination->verificationStamp===SMS_STAMP
            && $challengeAuthEpoch===SMS_EPOCH;
    }
}
function smsStore(PDO $db,?CiSmsNativeEligibility $eligibility=null):V5MySqlSmsOutbox {
    return new V5MySqlSmsOutbox($db,$eligibility??new CiSmsNativeEligibility(),
        new V5SodiumSmsMessageFactory(SMS_ENVELOPE_KEY,SMS_HMAC_KEY),BIND_KEY);
}
function smsChallenge(PDO $db,string $id):void {
    $t=(int)$db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $stmt=$db->prepare('INSERT INTO ev_v5_auth_challenges '
        .'(challenge_id,user_id,device_binding,platform,kind,status,password_proven,'
        .'issued_at,expires_at,failed_attempts,sms_sent_count,last_sms_sent_at,'
        .'verification_stamp,auth_epoch,sms_code_hmac,current_delivery_id) '
        .'VALUES (?,41,?,?,"sms","pending","Y",?,?,0,0,NULL,NULL,?,NULL,NULL)');
    $stmt->execute([
        $id,V5MySqlSmsOutbox::installationBinding(DEVICE_ID,'ios',BIND_KEY),
        'ios',$t-120,$t+150,SMS_EPOCH,
    ]);
}
function smsWindows(int $offset,int $costCap=3600):array {
    $out=[];$now=time();
    for($i=0;$i<10;$i++) {
        $key=str_repeat(sprintf('%02x',$offset+$i),32);
        $isCost=$i===9;
        $out[]=new V5SmsBudgetWindow($key,
            $isCost?$costCap:4,$isCost?1200:1,$now+3600);
    }
    return $out;
}
function smsRequest(string $challenge,array $windows,bool $resend=false):V5SmsQueueReservation {
    return new V5SmsQueueReservation(
        41,$challenge,DEVICE_ID,'ios',$resend,
        new V5VerifiedSmsDestination(41,SMS_PHONE,SMS_STAMP),
        new V5SmsPriceCeiling(1200,'RUB'),$windows
    );
}
function smsRace(string $challenge,array $windows):int {
    try {
        $db=smsDb();
        smsStore($db)->reserveAndQueue(smsRequest($challenge,$windows));
        return 0;
    } catch(AuthenticationRejected) {return 1;}
    catch(Throwable) {return 2;}
}

$db=smsDb();
smsCheck($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql',
    'real PDO MariaDB MySQL driver');

// Apply EXACT three CREATE statements from approved PROPOSAL to CI-only DB,
// never use DROP/TRUNCATE/DELETE or execute comments/unknown SQL.
$ddl=file_get_contents(__DIR__.'/../../schema/PROPOSED_ev_v5_sms_challenge_outbox.sql');
if(!is_string($ddl)||strlen($ddl)>10000)
    throw new RuntimeException('SMS DDL proposal unavailable');
$tables=['ev_v5_auth_challenges','ev_v5_sms_budget_windows','ev_v5_sms_outbox'];
foreach($tables as $table) {
    $prefix='CREATE TABLE '.$table.' (';
    if(substr_count($ddl,$prefix)!==1)throw new RuntimeException('DDL table identity mismatch');
    $start=strpos($ddl,$prefix);
    $end=strpos($ddl,';',$start);
    if($end===false)throw new RuntimeException('DDL statement boundary');
    $sql=substr($ddl,$start,$end-$start+1);
    if(!str_contains($sql,'ENGINE=InnoDB')
        || !str_contains($sql,'DEFAULT CHARSET=utf8mb4'))
        throw new RuntimeException('DDL engine/charset guard');
    $db->exec($sql);
}
smsCheck(smsCount($db,'ev_v5_sms_outbox')===0
    && smsCount($db,'ev_v5_sms_budget_windows')===0,
    'new synthetic InnoDB outbox and quota tables empty');
foreach($tables as $table){
    $q=$db->prepare('SELECT ENGINE FROM information_schema.TABLES '
        .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);
    smsCheck($q->fetchColumn()==='InnoDB','each disposable SMS table InnoDB');
}
smsChallenge($db,SMS_CHALLENGE);
$windows=smsWindows(0,1200);
$store=smsStore($db);
$first=$store->reserveAndQueue(smsRequest(SMS_CHALLENGE,$windows));
smsCheck($first instanceof V5SmsQueuedDelivery && !$db->inTransaction(),
    'real InnoDB SMS envelope queued and committed');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===1 && smsCount($db,'ev_v5_sms_budget_windows')===10,
    'all ten quota reservations and single encrypted outbox atomic');
$challenge=$db->query('SELECT * FROM ev_v5_auth_challenges LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$outbox=$db->query('SELECT * FROM ev_v5_sms_outbox LIMIT 1')->fetch(PDO::FETCH_ASSOC);
smsCheck((int)$challenge['sms_sent_count']===1
    && $challenge['current_delivery_id']===$first->deliveryId
    && (int)$outbox['send_ordinal']===1
    && $outbox['status']==='queued',
    'challenge and outbox refer to same first queued delivery');
smsCheck($outbox['encrypted_envelope']!=='' && !str_contains($outbox['encrypted_envelope'],SMS_PHONE)
    && !str_contains($outbox['encrypted_envelope'],'Код подтверждения')
    && preg_match('/\A[a-f0-9]{64}\z/D',$challenge['sms_code_hmac'])===1,
    'no plaintext phone/code at rest and HMAC-only verifier');
smsCheck($outbox['provider_idempotency_key']===$first->deliveryId,
    'provider idempotency key persisted; no paid send executed');
$costKey=$windows[9]->bucketKey;
$q=$db->prepare('SELECT used_units FROM ev_v5_sms_budget_windows WHERE bucket_key=?');
$q->execute([$costKey]);
smsCheck((int)$q->fetchColumn()===1200,'real global cost window charged 1200 minor units');

$second=str_repeat('d',64);
smsChallenge($db,$second);
$beforeBudget=$db->query('SELECT SUM(used_units) FROM ev_v5_sms_budget_windows')->fetchColumn();
smsDeny(fn()=>$store->reserveAndQueue(smsRequest($second,$windows)),
    'second SMS rejected when cost ceiling exhausted');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===1
    && $db->query('SELECT SUM(used_units) FROM ev_v5_sms_budget_windows')->fetchColumn()===$beforeBudget,
    'outbox and all preceding quota increments rolled back on over-budget');

$sameRequest=smsRequest(SMS_CHALLENGE,$windows);
smsDeny(fn()=>$store->reserveAndQueue($sameRequest),
    'original challenge cannot reserve duplicate initial SMS');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===1,
    'duplicate first send did not create another paid-delivery candidate');

// Resend respects 60s cooldown and rotates delivery HMAC/envelope.
$resendWindows=smsWindows(16,3600);
smsDeny(fn()=>$store->reserveAndQueue(smsRequest(SMS_CHALLENGE,$resendWindows,true)),
    'resend before cooldown rejected');
$db->exec('UPDATE ev_v5_auth_challenges SET last_sms_sent_at=UNIX_TIMESTAMP()-61 '
    ."WHERE challenge_id='".SMS_CHALLENGE."'");
$resend=$store->reserveAndQueue(smsRequest(SMS_CHALLENGE,$resendWindows,true));
smsCheck($resend->deliveryId!==$first->deliveryId
    && smsCount($db,'ev_v5_sms_outbox')===2,
    'resend commits only one NEW encrypted outbox message');
$q=$db->prepare('SELECT sms_sent_count,current_delivery_id,sms_code_hmac '
    .'FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([SMS_CHALLENGE]);$later=$q->fetch(PDO::FETCH_ASSOC);
smsCheck((int)$later['sms_sent_count']===2
    && $later['current_delivery_id']===$resend->deliveryId
    && $later['sms_code_hmac']!==$challenge['sms_code_hmac'],
    'resend increments ordinal and invalidates prior SMS HMAC');
smsDeny(fn()=>$store->reserveAndQueue(smsRequest(SMS_CHALLENGE,$resendWindows,true)),
    'second immediate resend denied by DB cooldown');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===2,
    'cooldown denial leaves paid delivery count unchanged');

$denyChallenge=str_repeat('a',64);
smsChallenge($db,$denyChallenge);
$site=new CiSmsNativeEligibility();$site->allow=false;
$denied=smsStore($db,$site);
smsDeny(fn()=>$denied->reserveAndQueue(smsRequest($denyChallenge,smsWindows(48))),
    'site verified-phone/epoch callback denial blocks SMS');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===2,
    'site-proof denial made zero persistent paid outbox writes');

// Race a fresh challenge using TWO real MariaDB connections/processes.
$raceChallenge=str_repeat('b',64);
smsChallenge($db,$raceChallenge);
$raceWindows=smsWindows(32,3600);
$baseCount=smsCount($db,'ev_v5_sms_outbox');
unset($db,$store,$denied,$site,$first,$resend);
gc_collect_cycles();
$pid=pcntl_fork();
if($pid===-1)throw new RuntimeException('ephemeral fork unavailable');
if($pid===0)exit(smsRace($raceChallenge,$raceWindows));
$parent=smsRace($raceChallenge,$raceWindows);
$child=pcntl_waitpid($pid,$status);
$exit=pcntl_wifexited($status)?pcntl_wexitstatus($status):2;
smsCheck($child===$pid && in_array($parent,[0,1],true)
    && in_array($exit,[0,1],true)
    && ($parent===0?1:0)+($exit===0?1:0)===1,
    'two real MariaDB processes queue EXACTLY one initial SMS for same challenge');
$db=smsDb();
smsCheck(smsCount($db,'ev_v5_sms_outbox')===$baseCount+1,
    'racing first sends cannot enqueue a second provider charge');
$q=$db->prepare('SELECT sms_sent_count FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$raceChallenge]);
smsCheck((int)$q->fetchColumn()===1,
    'concurrent challenge SMS send count increments only once');

echo "PASS: real ephemeral MariaDB SMS quota/outbox ($checks assertions)\n";
echo "MODE=GITHUB_EPHEMERAL_CI_ONLY\nREAL_MARIADB_INNODB=YES\n";
echo "TWO_PROCESS_SMS_FIRST_SEND_RACE=YES\n";
echo "ENCRYPTED_OUTBOX_ONLY=YES\nACTUAL_SMS_SENT=NO\n";
echo "REAL_BITRIX_BOOTSTRAP=NO\nNATIVE_PHONE_WRITER_ATTESTED=NO\n";
echo "PRODUCTION_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
