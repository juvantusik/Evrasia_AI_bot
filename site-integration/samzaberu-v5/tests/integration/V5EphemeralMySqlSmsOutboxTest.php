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
require_once __DIR__ . '/../../src/V5MySqlChallengeConsumption.php';
require_once __DIR__ . '/../../src/V5MySqlConsumedChallengeProof.php';
require_once __DIR__ . '/../../src/V5MySqlChallengeHttpPort.php';
require_once __DIR__ . '/../../src/V5MySqlSessionActions.php';
require_once __DIR__ . '/../../src/V5HttpTransportBoundary.php';
require_once __DIR__ . '/../../src/V5MySqlPasswordBoundTotpIssuer.php';
require_once __DIR__ . '/../../src/V5MySqlSmsDispatchClaims.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlSmsOutbox,V5SmsLockedEligibilityPort,V5SmsQueueReservation,
    V5VerifiedSmsDestination,V5SmsPriceCeiling,V5SmsBudgetWindow,
    V5SodiumSmsMessageFactory,V5SmsQueuedDelivery,AuthenticationRejected,
    V5MySqlChallengeConsumption,V5LockedChallengeNativeFactorPort,
    ConsumedLoginChallenge,ChallengeKind,V5MySqlConsumedChallengeProof,
    V5LockedStrongSessionProofPort,V5NativeSecurityHookCoveragePort,
    V5MySqlStrongSessionStore,V5AccessJwtCodec,V5OpaqueRefreshTokens,
    PasswordVerifiedAccount,AuthenticatedV5Session,
    V5MySqlChallengeHttpPort,V5MySqlSessionActions,
    V5PasswordProofPort,V5TrustProofPort,MobileTrustAssessment,
    V5LockedBonusPinReaderPort,V5SmsSentReceiptAttestationPort,V5LoginWorkflow,
    V5AuthHttpHandler,V5HttpTransportBoundary,V5StagedHttpRequest,
    V5MySqlPasswordBoundTotpIssuer,V5LockedPasswordTotpChallengeProofPort,
    V5MySqlSmsDispatchClaims,V5LockedSmsDispatchSafetyPort,
    V5ClaimedSmsForOneAttempt,V5SmsProviderTerminalOutcome
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


/**
 * Native Bitrix verifier is NOT present. This mandatory synthetic callback
 * proves only transaction placement; real MFA, phone/epoch and TOTP replay
 * checks must be supplied by the actual attested Bitrix staging provider.
 */
final class CiChallengeNativeFactor implements V5LockedChallengeNativeFactorPort {
    public bool $allow=true;
    public int $totpChecks=0;
    public bool $enforceCurrentEpoch=false;
    public function currentFactorEligibleWithLocks(
        PDO $db,int $userId,ChallengeKind $kind,
        string $challengeAuthEpoch,?string $verifiedPhoneStamp
    ):bool {
        $epochStamp=SMS_EPOCH;
        if ($this->enforceCurrentEpoch) {
            $query=$db->prepare('SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
                .'WHERE USER_ID=? FOR UPDATE');
            $query->execute([$userId]);
            $current=$query->fetchColumn();
            if(!is_string($current) && !is_int($current))return false;
            $epochStamp=hash_hmac('sha256',
                "ci:locked-totp-epoch:v1\0".$current,str_repeat('J',64));
        }
        return $this->allow && $db->inTransaction()
            && $userId===41 && $challengeAuthEpoch===$epochStamp
            && ($kind===ChallengeKind::SMS
                ? $verifiedPhoneStamp===SMS_STAMP
                : $verifiedPhoneStamp===null);
    }
    public function consumeNativeTotpWithLocks(PDO $db,int $userId,string $code):bool {
        $this->totpChecks++;
        return $this->allow && $db->inTransaction()
            && $userId===41 && $code==='123456';
    }
}
function ciConsumer(PDO $db,?CiChallengeNativeFactor $native=null):V5MySqlChallengeConsumption {
    return new V5MySqlChallengeConsumption(
        $db,$native??new CiChallengeNativeFactor(),BIND_KEY,SMS_HMAC_KEY
    );
}
function ciSmsCode(PDO $db,string $id):string {
    $q=$db->prepare('SELECT delivery_id,encrypted_envelope FROM ev_v5_sms_outbox '
        .'WHERE challenge_id=? ORDER BY send_ordinal DESC LIMIT 1');
    $q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))throw new RuntimeException('CI encrypted synthetic SMS not found');
    $bytes=base64_decode($row['encrypted_envelope'],true);
    $nonceLen=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    if(!is_string($bytes)||strlen($bytes)<$nonceLen+16)
        throw new RuntimeException('CI nonce/ciphertext unavailable');
    $decoded=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        substr($bytes,$nonceLen),
        "evrasia:v5:sms-outbox:v1\0".$row['delivery_id'],
        substr($bytes,0,$nonceLen),SMS_ENVELOPE_KEY
    );
    if(!is_string($decoded))throw new RuntimeException('synthetic decrypt failure');
    $message=json_decode($decoded,true,8,JSON_THROW_ON_ERROR);
    if(!preg_match('/([0-9]{6})/',$message['text']??'',$m))
        throw new RuntimeException('synthetic SMS code format absent');
    return $m[1]; // synthetic CI only; NEVER print code or real envelope.
}
function ciConsumedRace(string $id):int {
    try {
        $db=smsDb();
        $grant=ciConsumer($db)->verifyAndConsume($id,'123456',DEVICE_ID,'ios');
        return $grant instanceof ConsumedLoginChallenge?0:1;
    }catch(Throwable){return 2;}
}

$db=smsDb();
$smsVerifiedId=str_repeat('1',64);
smsChallenge($db,$smsVerifiedId);
$verifierStore=smsStore($db);
$pendingDelivery=$verifierStore->reserveAndQueue(
    smsRequest($smsVerifiedId,smsWindows(64,3600))
);
$code=ciSmsCode($db,$smsVerifiedId);
$verify=ciConsumer($db);
$knownWrongCode=$code==='999999'?'999998':'999999';
smsCheck($verify->verifyAndConsume($smsVerifiedId,$knownWrongCode,DEVICE_ID,'ios')===null,
    'incorrect six-digit SMS code denied in actual InnoDB transaction');
$q=$db->prepare('SELECT failed_attempts,status FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$smsVerifiedId]);$wrong=$q->fetch(PDO::FETCH_ASSOC);
smsCheck((int)$wrong['failed_attempts']===1 && $wrong['status']==='pending',
    'incorrect SMS attempt is durably charged');
smsCheck($verify->verifyAndConsume($smsVerifiedId,$code,DEVICE_ID,'ios')===null,
    'queued SMS must NOT authorize a code without a positive sent record');
$q=$db->prepare('SELECT failed_attempts FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$smsVerifiedId]);
smsCheck((int)$q->fetchColumn()===2,
    'guess against queued delivery counts as an unsuccessful attempt');
// Simulate a positive provider acknowledgement ONLY in this isolated
// database. There is no real gateway and no sending of the synthetic SMS.
$q=$db->prepare('UPDATE ev_v5_sms_outbox SET status=? WHERE delivery_id=?');
$q->execute(['sent',$pendingDelivery->deliveryId]);
$native=new CiChallengeNativeFactor();
$verified=ciConsumer($db,$native);
$grant=$verified->verifyAndConsume($smsVerifiedId,$code,DEVICE_ID,'ios');
smsCheck($grant instanceof ConsumedLoginChallenge && $grant->kind===ChallengeKind::SMS
    && $grant->grantReference===$smsVerifiedId,
    'real InnoDB accepts valid HMAC for ONLY provider-confirmed sent SMS');
$q=$db->prepare('SELECT status,sms_code_hmac FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$smsVerifiedId]);$done=$q->fetch(PDO::FETCH_ASSOC);
smsCheck($done['status']==='consumed' && $done['sms_code_hmac']===null,
    'successful code is atomically consumed and SMS HMAC cleared');
smsCheck($verified->verifyAndConsume($smsVerifiedId,$code,DEVICE_ID,'ios')===null,
    'SMS challenge cannot be consumed twice, even with correct code');
smsCheck($verified->verifyAndConsume($smsVerifiedId,$code,DEVICE_ID,'android')===null,
    'changing mobile platform cannot bypass challenge binding');

$totpId=str_repeat('2',64);
smsChallenge($db,$totpId);
$q=$db->prepare('UPDATE ev_v5_auth_challenges SET kind=? WHERE challenge_id=?');
$q->execute(['totp',$totpId]);
$totpNative=new CiChallengeNativeFactor();
$totpVerify=ciConsumer($db,$totpNative);
smsCheck($totpVerify->verifyAndConsume($totpId,'000000',DEVICE_ID,'ios')===null,
    'synthetic native TOTP wrong code denied');
$q=$db->prepare('SELECT failed_attempts FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$totpId]);smsCheck((int)$q->fetchColumn()===1,
    'wrong TOTP attempt is durably charged in InnoDB');
$totpGrant=$totpVerify->verifyAndConsume($totpId,'123456',DEVICE_ID,'ios');
smsCheck($totpGrant instanceof ConsumedLoginChallenge
    && $totpGrant->kind===ChallengeKind::TOTP
    && $totpNative->totpChecks===2,
    'synthetic native TOTP factor is consumed only in owned DB transaction');
smsCheck($totpVerify->verifyAndConsume($totpId,'123456',DEVICE_ID,'ios')===null,
    'consumed synthetic native TOTP cannot replay');


/** Staging proof composition: the trusted-device proof is separate and
 * disabled in this synthetic SMS/TOTP-only end-to-end fixture. */
final class CiNoTrustProof implements V5LockedStrongSessionProofPort {
    public function verifiedTrustWithUserLock(
        PDO $db,PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken
    ):bool {return false;}
    public function verifiedConsumedGrantWithUserLock(
        PDO $db,ConsumedLoginChallenge $grant
    ):bool {return false;}
}
final class CiGrantSessionCoverage implements V5NativeSecurityHookCoveragePort {
    public function fullyCovered(PDO $db,int $userId):?bool {
        return $db->inTransaction() && $userId===41;
    }
}
$grantNative=new CiChallengeNativeFactor();
$grantProof=new V5MySqlConsumedChallengeProof(
    new CiNoTrustProof(),$grantNative,BIND_KEY
);
$jwt=new V5AccessJwtCodec(['v5_ci'=>str_repeat('K',64)],'v5_ci');
$sessions=new V5MySqlStrongSessionStore(
    $db,$jwt,new V5OpaqueRefreshTokens(str_repeat('R',64)),
    $grantProof,new CiGrantSessionCoverage(),str_repeat('Z',64),3600
);
$beforeIssued=(int)$db->query('SELECT COUNT(*) FROM ev_v5_strong_sessions')
    ->fetchColumn();
$grantNative->allow=false;
smsDeny(fn()=>$sessions->afterConsumedChallenge($grant),
    'unattested native factor state prevents JWT issuance after consumed SMS');
smsCheck((int)$db->query('SELECT COUNT(*) FROM ev_v5_strong_sessions')
    ->fetchColumn()===$beforeIssued,
    'native recheck denial cannot mint a V5 session');
$grantNative->allow=true;
$auth=$sessions->afterConsumedChallenge($grant);
$authClaims=$jwt->verifyAccess($auth->accessToken,time());
smsCheck($auth instanceof AuthenticatedV5Session
    && is_array($authClaims)
    && $authClaims['auth_epoch']>=1
    && $authClaims['sid']!=='' ,
    'consumed sent-SMS proof issues a real committed InnoDB V5 session');
$q=$db->prepare('SELECT assurance,grant_digest FROM ev_v5_strong_sessions WHERE sid=?');
$q->execute([$authClaims['sid']]);$issued=$q->fetch(PDO::FETCH_ASSOC);
smsCheck($issued['assurance']==='sms'
    && preg_match('/\A[a-f0-9]{64}\z/D',$issued['grant_digest'])===1,
    'SMS session stores strong-factor evidence only as keyed grant digest');
smsDeny(fn()=>$sessions->afterConsumedChallenge($grant),
    'same SMS challenge cannot create a second V5 session');
smsCheck((int)$db->query('SELECT COUNT(*) FROM ev_v5_strong_sessions')
    ->fetchColumn()===$beforeIssued+1,
    'DB UNIQUE grant digest prohibits duplicate JWT session from SMS grant');

$totpAuth=$sessions->afterConsumedChallenge($totpGrant);
$totpClaims=$jwt->verifyAccess($totpAuth->accessToken,time());
$q=$db->prepare('SELECT assurance,grant_digest FROM ev_v5_strong_sessions WHERE sid=?');
$q->execute([$totpClaims['sid']]);$totpRow=$q->fetch(PDO::FETCH_ASSOC);
smsCheck(is_array($totpClaims) && $totpRow['assurance']==='totp'
    && $totpRow['grant_digest']!==$issued['grant_digest'],
    'synthetic native TOTP consumed grant issues separate strong V5 session');
smsDeny(fn()=>$sessions->afterConsumedChallenge($totpGrant),
    'same synthetic native TOTP grant cannot create second V5 session');
smsCheck((int)$db->query('SELECT COUNT(*) FROM ev_v5_strong_sessions')
    ->fetchColumn()===$beforeIssued+2,
    'real InnoDB consumed SMS/TOTP grant-to-session path commits two unique rows');


$blockedId=str_repeat('3',64);
smsChallenge($db,$blockedId);
$q=$db->prepare('UPDATE ev_v5_auth_challenges SET kind=? WHERE challenge_id=?');
$q->execute(['totp',$blockedId]);
$deniedNative=new CiChallengeNativeFactor();$deniedNative->allow=false;
smsCheck(ciConsumer($db,$deniedNative)->verifyAndConsume(
    $blockedId,'123456',DEVICE_ID,'ios')===null,
    'unverified Bitrix native status refuses to consume challenge');
$q=$db->prepare('SELECT status FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$blockedId]);
smsCheck($q->fetchColumn()==='pending','native denial never changes challenge to consumed');

$lockoutId=str_repeat('4',64);
smsChallenge($db,$lockoutId);
$q=$db->prepare('UPDATE ev_v5_auth_challenges SET kind=? WHERE challenge_id=?');
$q->execute(['totp',$lockoutId]);
for($i=0;$i<5;$i++)
    smsCheck(ciConsumer($db)->verifyAndConsume(
        $lockoutId,'000000',DEVICE_ID,'ios')===null,
        'invalid TOTP code never consumed while exhausting five attempts');
$q=$db->prepare('SELECT status,failed_attempts FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$lockoutId]);$locked=$q->fetch(PDO::FETCH_ASSOC);
smsCheck($locked['status']==='cancelled' && (int)$locked['failed_attempts']===5,
    'five invalid attempts atomically cancel a challenge');
smsCheck(ciConsumer($db)->verifyAndConsume(
    $lockoutId,'123456',DEVICE_ID,'ios')===null,
    'correct code after lockout never reopens cancelled challenge');


final class CiHttpPasswordDisabled implements V5PasswordProofPort {
    public function verifyWithoutLogin(string $login,string $password):?PasswordVerifiedAccount {
        return null; // No actual first-factor provider has been attested.
    }
}
final class CiHttpTrustDisabled implements V5TrustProofPort {
    public function evaluate(int $userId,string $deviceId,string $platform,
        string $token):MobileTrustAssessment {
        return new MobileTrustAssessment(false,false);
    }
}
final class CiHttpPinReader implements V5LockedBonusPinReaderPort {
    public int $calls=0;
    public function readLockedBonusPin(PDO $db,int $userId):?string {
        if(!$db->inTransaction() || $userId!==41)
            throw new RuntimeException('synthetic PIN lock required');
        $this->calls++;return '00037';
    }
}
// A challenge created by a SYNTHETIC password-proven CI fixture, not by
// an HTTP request. Only verify/consume is connected; begin/resend are denied.
$httpCid=str_repeat('6',64);
smsChallenge($db,$httpCid);
$httpQueued=smsStore($db)->reserveAndQueue(
    smsRequest($httpCid,smsWindows(112,3600))
);
$httpCode=ciSmsCode($db,$httpCid);
$q=$db->prepare('UPDATE ev_v5_sms_outbox SET status=? WHERE delivery_id=?');
$q->execute(['sent',$httpQueued->deliveryId]); // Synthetic acknowledgement only.
$challengeHttp=new V5MySqlChallengeHttpPort(ciConsumer($db));
$httpPinReader=new CiHttpPinReader();
$workflow=new V5LoginWorkflow(
    new CiHttpPasswordDisabled(),new CiHttpTrustDisabled(),
    $challengeHttp,$sessions
);
$boundary=new V5HttpTransportBoundary(
    new V5AuthHttpHandler($workflow,
        new V5MySqlSessionActions($sessions,$httpPinReader)
    )
);
$httpReq=static function(string $path,array $body):V5StagedHttpRequest {
    $json=json_encode($body,JSON_THROW_ON_ERROR);
    return new V5StagedHttpRequest(
        'POST',$path,true,'application/json',$json,(string)strlen($json)
    );
};
smsDeny(fn()=>$challengeHttp->begin(41,DEVICE_ID,'ios',ChallengeKind::SMS),
    'missing native password-bound beginning refuses an SMS challenge');
smsDeny(fn()=>$challengeHttp->resendSms($httpCid,DEVICE_ID,'ios'),
    'missing native SMS delivery refuses resend without any provider call');
$verifyBody=[
    'challenge_id'=>$httpCid,'code'=>$httpCode,
    'device_id'=>DEVICE_ID,'platform'=>'ios',
];
$confirmed=$boundary->dispatch($httpReq('/api/v5/auth/challenge/verify',$verifyBody));
$credentials=json_decode($confirmed->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($confirmed->status===200 && isset($credentials['access_token'],
    $credentials['refresh_token']),
    'real InnoDB six-digit challenge verification reaches V5 HTTP token response');
smsCheck(is_array($jwt->verifyAccess($credentials['access_token'],time())),
    'HTTP-confirmed grant issues a valid V5 access JWT from committed SQL');
$replay=$boundary->dispatch($httpReq('/api/v5/auth/challenge/verify',$verifyBody));
smsCheck($replay->status===401
    && $replay->jsonBody==='{"error":"authentication_failed"}',
    'replaying same code through HTTP cannot issue a second session');
$pinResponse=$boundary->dispatch($httpReq('/api/v5/loyalty/pin',[
    'access_token'=>$credentials['access_token']
]));
$pinData=json_decode($pinResponse->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($pinResponse->status===200 && ($pinData['pin']??null)==='00037'
    && $httpPinReader->calls===1,
    'new code-authenticated JWT reads bonus PIN without a second SMS');
$refreshReply=$boundary->dispatch($httpReq('/api/v5/auth/refresh',[
    'refresh_token'=>$credentials['refresh_token']
]));
$renewal=json_decode($refreshReply->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($refreshReply->status===200 && isset($renewal['refresh_token'])
    && $renewal['refresh_token']!==$credentials['refresh_token'],
    'code-confirmed V5 session rotates refresh through real InnoDB');
smsCheck($boundary->dispatch($httpReq('/api/v5/auth/refresh',[
    'refresh_token'=>$credentials['refresh_token']
]))->status===401,
    'HTTP replay of code-confirmed session refresh rejected');
smsCheck($sessions->revokeAccess($renewal['access_token']),
    'code-confirmed access JWT can revoke its own real InnoDB session');
smsCheck($boundary->dispatch($httpReq('/api/v5/loyalty/pin',[
    'access_token'=>$renewal['access_token']
]))->status===401,
    'revoked code-authenticated HTTP token cannot fetch PIN');
smsCheck($httpPinReader->calls===1,
    'revocation is enforced before reading sensitive PIN');


/**
 * Real InnoDB initial TOTP issuance after HTTP password proof. The external
 * first-factor fixture deliberately accepts a stale password, whereas the
 * site-native SYNTHETIC locked recheck rejects it before any challenge insert.
 * Neither fake verifier is a substitute for actual Bitrix auth/MFA methods.
 */
final class CiHttpTotpInitialPassword implements V5PasswordProofPort {
    public int $calls=0;
    public function verifyWithoutLogin(string $login,string $password):?PasswordVerifiedAccount {
        $this->calls++;
        if($login!=='synthetic-totp-user'
            || !in_array($password,['ci-fresh-password','ci-stale-password'],true))return null;
        return new PasswordVerifiedAccount(41,true,true);
    }
}
final class CiLockedPasswordTotpProof implements V5LockedPasswordTotpChallengeProofPort {
    public int $calls=0;
    public bool $allow=true;
    public function reverifyCurrentPasswordAndTotpWithUserLock(
        PDO $db,PasswordVerifiedAccount $account,string $login,
        string $password,int $lockedAuthEpoch
    ):?string {
        $this->calls++;
        if(!$this->allow || !$db->inTransaction()
            || $account->userId!==41 || !$account->nativeTotpActive
            || $login!=='synthetic-totp-user'
            || $password!=='ci-fresh-password'
            || $lockedAuthEpoch<=0) return null;
        // REAL DB epoch, SYNTHETIC revalidation of password and native MFA.
        $q=$db->prepare('SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state '
            .'WHERE USER_ID=? FOR UPDATE');
        $q->execute([41]);
        if((int)$q->fetchColumn()!==$lockedAuthEpoch)return null;
        return hash_hmac('sha256',
            "ci:locked-totp-epoch:v1\0".$lockedAuthEpoch,str_repeat('J',64));
    }
}
$reproof=new CiLockedPasswordTotpProof();
$totpIssuer=new V5MySqlPasswordBoundTotpIssuer($db,$reproof,BIND_KEY);
$totpFactor=new CiChallengeNativeFactor();
$totpFactor->enforceCurrentEpoch=true;
$totpProof=new V5MySqlConsumedChallengeProof(
    new CiNoTrustProof(),$totpFactor,BIND_KEY
);
$totpSessions=new V5MySqlStrongSessionStore(
    $db,new V5AccessJwtCodec(['totp_ci'=>str_repeat('K',64)],'totp_ci'),
    new V5OpaqueRefreshTokens(str_repeat('R',64)),
    $totpProof,new CiGrantSessionCoverage(),str_repeat('Z',64),3600
);
$totpPort=new V5MySqlChallengeHttpPort(
    ciConsumer($db,$totpFactor),$totpIssuer
);
$totpFirst=new CiHttpTotpInitialPassword();
$totpBoundary=new V5HttpTransportBoundary(
    new V5AuthHttpHandler(
        new V5LoginWorkflow($totpFirst,new CiHttpTrustDisabled(),
            $totpPort,$totpSessions),
        new V5MySqlSessionActions($totpSessions,$httpPinReader)
    )
);
$totpLogin=[
    'login'=>'synthetic-totp-user','password'=>'ci-fresh-password',
    'device_id'=>DEVICE_ID,'platform'=>'ios',
];
$pendingBefore=(int)$db->query('SELECT COUNT(*) FROM ev_v5_auth_challenges')
    ->fetchColumn();
$smsBefore=smsCount($db,'ev_v5_sms_outbox');
$badLogin=$totpLogin;$badLogin['password']='ci-stale-password';
$bad=$totpBoundary->dispatch($httpReq('/api/v5/auth/signin',$badLogin));
smsCheck($bad->status===401 && $reproof->calls===1,
    'outdated external password proof reverified and refused UNDER user lock');
smsCheck((int)$db->query('SELECT COUNT(*) FROM ev_v5_auth_challenges')
    ->fetchColumn()===$pendingBefore,
    'stale password cannot insert an epoch-bound challenge');

smsDeny(fn()=>$totpIssuer->begin(
    41,DEVICE_ID,'ios',ChallengeKind::TOTP),
    'userId-only TOTP issuance cannot bypass password-bound native recheck');
smsDeny(fn()=>$totpPort->begin(
    41,DEVICE_ID,'ios',ChallengeKind::TOTP),
    'V5AtomicChallengePort legacy shortcut always denied');
smsDeny(fn()=>$totpIssuer->beginBound(
    new PasswordVerifiedAccount(41,true,false),
    'synthetic-totp-user','ci-fresh-password',DEVICE_ID,'ios',
    ChallengeKind::TOTP),
    'missing native TOTP activation cannot create challenge');
smsDeny(fn()=>$totpIssuer->beginBound(
    new PasswordVerifiedAccount(41,true,true),
    'synthetic-totp-user','ci-fresh-password',DEVICE_ID,'ios',
    ChallengeKind::SMS),
    'SMS challenge issuance disabled without paid gateway and atomic quotas');
$reproof->allow=false;
smsDeny(fn()=>$totpBoundary->dispatch($httpReq(
    '/api/v5/auth/signin',$totpLogin
))->status===202?null:throw new RuntimeException('locked proof refused'),
    'disabled locked native proof fails closed');
$reproof->allow=true;
// Recheck a native account block in the b_user row; synthetic fixture only.
$db->exec("UPDATE b_user SET BLOCKED='Y' WHERE ID=41");
$blocked=$totpBoundary->dispatch($httpReq('/api/v5/auth/signin',$totpLogin));
smsCheck($blocked->status===401,
    'native blocked account cannot create challenge after password proof');
$db->exec("UPDATE b_user SET BLOCKED='N' WHERE ID=41");
smsCheck((int)$db->query('SELECT COUNT(*) FROM ev_v5_auth_challenges')
    ->fetchColumn()===$pendingBefore,
    'all rejected initial proofs have zero persistent challenge rows');

$firstBegin=$totpBoundary->dispatch($httpReq('/api/v5/auth/signin',$totpLogin));
$firstTicket=json_decode($firstBegin->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($firstBegin->status===202
    && ($firstTicket['status']??null)==='challenge_required'
    && ($firstTicket['challenge_type']??null)==='totp'
    && ($firstTicket['expires_in']??null)===300
    && preg_match('/\A[a-f0-9]{64}\z/D',$firstTicket['challenge_id']??'')===1,
    'real InnoDB HTTP login creates a fresh 300s locked password-proven TOTP ticket');
$firstId=$firstTicket['challenge_id'];
$q=$db->prepare('SELECT * FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$firstId]);$issuedRow=$q->fetch(PDO::FETCH_ASSOC);
$expectedStamp=hash_hmac('sha256',
    "ci:locked-totp-epoch:v1\0".$db->query(
        'SELECT AUTH_EPOCH FROM ev_samzaberu_auth_state WHERE USER_ID=41'
    )->fetchColumn(),str_repeat('J',64));
smsCheck($issuedRow['password_proven']==='Y'
    && $issuedRow['kind']==='totp'
    && $issuedRow['status']==='pending'
    && $issuedRow['auth_epoch']===$expectedStamp
    && $issuedRow['verification_stamp']===null
    && $issuedRow['sms_code_hmac']===null
    && $issuedRow['current_delivery_id']===null
    && (int)$issuedRow['sms_sent_count']===0,
    'real challenge stores keyed current epoch, device binding and no SMS data');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===$smsBefore,
    'fresh TOTP issuance neither queues nor sends paid SMS');

$staleVerify=[
    'challenge_id'=>$firstId,'code'=>'123456',
    'device_id'=>DEVICE_ID,'platform'=>'ios'
];
$db->beginTransaction();
$db->query('SELECT ID FROM b_user WHERE ID=41 FOR UPDATE')->fetch();
$db->exec('UPDATE ev_samzaberu_auth_state '
    .'SET AUTH_EPOCH=AUTH_EPOCH+1 WHERE USER_ID=41');
$db->commit();
$staleResponse=$totpBoundary->dispatch($httpReq(
    '/api/v5/auth/challenge/verify',$staleVerify
));
smsCheck($staleResponse->status===401,
    'password/MFA epoch mutation invalidates pending TOTP ticket before verification');
$q=$db->prepare('SELECT status FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$firstId]);
smsCheck($q->fetchColumn()==='pending',
    'invalidated TOTP ticket was NOT silently accepted/consumed');

$active=$totpBoundary->dispatch($httpReq('/api/v5/auth/signin',$totpLogin));
$activeTicket=json_decode($active->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($active->status===202
    && $activeTicket['challenge_id']!==$firstId,
    'new password-bound TOTP challenge uses current committed epoch');
$codeRequest=[
    'challenge_id'=>$activeTicket['challenge_id'],'code'=>'123456',
    'device_id'=>DEVICE_ID,'platform'=>'ios'
];
$access=$totpBoundary->dispatch($httpReq(
    '/api/v5/auth/challenge/verify',$codeRequest
));
$jwtRow=json_decode($access->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($access->status===200
    && isset($jwtRow['access_token'],$jwtRow['refresh_token']),
    'HTTP TOTP challenge validates native callback and creates V5 JWT on real SQL');
$totpJwtCodec=new V5AccessJwtCodec(
    ['totp_ci'=>str_repeat('K',64)],'totp_ci'
);
smsCheck(is_array($totpJwtCodec->verifyAccess($jwtRow['access_token'],time())),
    'real InnoDB TOTP login returned properly signed post-commit access JWT');
smsCheck($totpBoundary->dispatch($httpReq(
    '/api/v5/auth/challenge/verify',$codeRequest
))->status===401,
    'used TOTP code/challenge cannot mint another session');
$beforePinReads=$httpPinReader->calls;
$totpPin=$totpBoundary->dispatch($httpReq('/api/v5/loyalty/pin',[
    'access_token'=>$jwtRow['access_token']
]));
$totpPinData=json_decode($totpPin->jsonBody,true,8,JSON_THROW_ON_ERROR);
smsCheck($totpPin->status===200 && ($totpPinData['pin']??null)==='00037'
    && $httpPinReader->calls===$beforePinReads+1,
    'confirmed TOTP session reveals PIN without a second SMS');
smsCheck(smsCount($db,'ev_v5_sms_outbox')===$smsBefore,
    'entire TOTP HTTP login path never pays for or queues an SMS');

// Exactly one successful grant when two independent real connections
// race an existing TOTP challenge.
$raceId=str_repeat('5',64);
smsChallenge($db,$raceId);
$q=$db->prepare('UPDATE ev_v5_auth_challenges SET kind=? WHERE challenge_id=?');
$q->execute(['totp',$raceId]);
unset($db,$verify,$verified,$totpVerify,$totpNative,$verifierStore,$grant,$totpGrant,$native);
gc_collect_cycles();
$pid=pcntl_fork();
if($pid===-1)throw new RuntimeException('ephemeral consume race fork failed');
if($pid===0)exit(ciConsumedRace($raceId));
$parent=ciConsumedRace($raceId);
$waited=pcntl_waitpid($pid,$status);
$child=pcntl_wifexited($status)?pcntl_wexitstatus($status):2;
smsCheck($waited===$pid && in_array($parent,[0,1],true)
    && in_array($child,[0,1],true)
    && ($parent===0?1:0)+($child===0?1:0)===1,
    'two InnoDB processes can consume same TOTP challenge only once');
$db=smsDb();
$q=$db->prepare('SELECT status,failed_attempts FROM ev_v5_auth_challenges WHERE challenge_id=?');
$q->execute([$raceId]);$state=$q->fetch(PDO::FETCH_ASSOC);
smsCheck($state['status']==='consumed' && (int)$state['failed_attempts']===0,
    'one committed TOTP grant and zero failed attempts after race');

/**
 * A *different challenge* must NOT be able to reuse the same TOTP timestep.
 * This is deliberately SYNTHETIC: a tiny disposable InnoDB timecode ledger
 * models persisted Bitrix b_sec_user.PARAMS without pretending to implement
 * Otp::verify or claiming real native Bitrix E2E acceptance.
 *
 * Unlike the earlier test (two workers on ONE challenge_id), two distinct
 * pending challenges have independent row locks. Only the user-wide replay
 * marker should serialize them. All tables/users/codes below are CI fixtures.
 */
final class CiSyntheticDurableTotpReplay implements V5LockedChallengeNativeFactorPort {
    public function currentFactorEligibleWithLocks(
        PDO $db, int $userId, ChallengeKind $kind,
        string $challengeAuthEpoch, ?string $verifiedPhoneStamp
    ): bool {
        return $db->inTransaction() && $userId===41
            && $kind===ChallengeKind::TOTP && $challengeAuthEpoch===SMS_EPOCH
            && $verifiedPhoneStamp===null;
    }
    public function consumeNativeTotpWithLocks(PDO $db, int $userId, string $code): bool {
        if (!$db->inTransaction() || $userId!==41 || $code!=='123456') return false;
        // Exact same physical PDO transaction as challenge status/CAS. The
        // native Bitrix implementation is NOT called in this CI-only test.
        $q=$db->prepare('SELECT LAST_TIMECODE FROM ev_v5_ci_totp_replay_guard '
            .'WHERE USER_ID=? FOR UPDATE');
        $q->execute([$userId]);
        $last=$q->fetchColumn();
        if ($last===false || (int)$last!==0) return false;
        $u=$db->prepare('UPDATE ev_v5_ci_totp_replay_guard SET LAST_TIMECODE=1 '
            .'WHERE USER_ID=? AND LAST_TIMECODE=0');
        $u->execute([$userId]);
        return $u->rowCount()===1;
    }
}
function ciDistinctTotpRace(string $challengeId): int {
    try {
        $db=smsDb();
        $verifier=new V5MySqlChallengeConsumption(
            $db, new CiSyntheticDurableTotpReplay(), BIND_KEY, SMS_HMAC_KEY
        );
        $grant=$verifier->verifyAndConsume($challengeId,'123456',DEVICE_ID,'ios');
        return $grant instanceof ConsumedLoginChallenge ? 0 : 1;
    } catch (Throwable) { return 2; }
}

$db->exec('CREATE TABLE ev_v5_ci_totp_replay_guard '
    .'(USER_ID INT NOT NULL PRIMARY KEY, LAST_TIMECODE INT NOT NULL) '
    .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$db->exec('INSERT INTO ev_v5_ci_totp_replay_guard (USER_ID,LAST_TIMECODE) '
    .'VALUES (41,0)');
$separateA=hash('sha256','ci:v5:two-distinct-totp-challenges:a');
$separateB=hash('sha256','ci:v5:two-distinct-totp-challenges:b');
$freeIdCheck=$db->prepare('SELECT COUNT(*) FROM ev_v5_auth_challenges '
    .'WHERE challenge_id IN (?,?)');
$freeIdCheck->execute([$separateA,$separateB]);
smsCheck((int)$freeIdCheck->fetchColumn()===0 && $separateA!==$separateB,
    'distinct TOTP CI replay IDs do not collide with earlier fixtures');
foreach ([$separateA,$separateB] as $ticket) {
    smsChallenge($db,$ticket);
    $q=$db->prepare('UPDATE ev_v5_auth_challenges SET kind=? WHERE challenge_id=?');
    $q->execute(['totp',$ticket]);
}
$initialSeparate=$db->prepare('SELECT COUNT(*) FROM ev_v5_auth_challenges '
    .'WHERE challenge_id IN (?,?) AND status=? AND kind=?');
$initialSeparate->execute([$separateA,$separateB,'pending','totp']);
smsCheck((int)$initialSeparate->fetchColumn()===2,
    'two DIFFERENT valid TOTP challenge rows exist before replay race');

unset($db);
gc_collect_cycles();
$pid=pcntl_fork();
if ($pid===-1) throw new RuntimeException('ephemeral distinct TOTP race fork failed');
if ($pid===0) exit(ciDistinctTotpRace($separateA));
$parent=ciDistinctTotpRace($separateB);
$waited=pcntl_waitpid($pid,$status);
$child=pcntl_wifexited($status)?pcntl_wexitstatus($status):2;
smsCheck($waited===$pid
    && in_array($parent,[0,1],true) && in_array($child,[0,1],true)
    && (int)($parent===0)+(int)($child===0)===1,
    'two independent InnoDB workers cannot consume SAME synthetic timestep '
    .'from DIFFERENT challenge IDs');

$db=smsDb();
$q=$db->query('SELECT LAST_TIMECODE FROM ev_v5_ci_totp_replay_guard '
    .'WHERE USER_ID=41');
smsCheck((int)$q->fetchColumn()===1,
    'exactly one durable synthetic shared last_timecode advance');
$q=$db->prepare('SELECT challenge_id,status,failed_attempts FROM '
    .'ev_v5_auth_challenges WHERE challenge_id IN (?,?)');
$q->execute([$separateA,$separateB]);
$rows=$q->fetchAll(PDO::FETCH_ASSOC);
$consumed=0;$stillPending=0;
foreach ($rows as $row) {
    if ($row['status']==='consumed' && (int)$row['failed_attempts']===0) $consumed++;
    if ($row['status']==='pending' && (int)$row['failed_attempts']===1) $stillPending++;
}
smsCheck(count($rows)===2 && $consumed===1 && $stillPending===1,
    'one grant committed; losing independent challenge charged one failed attempt');

$loser=$parent===0 ? $separateA : $separateB;
smsCheck(ciDistinctTotpRace($loser)===1,
    'replaying consumed timestep in loser challenge remains denied');
$q=$db->prepare('SELECT failed_attempts,status FROM ev_v5_auth_challenges '
    .'WHERE challenge_id=?');
$q->execute([$loser]);$loserState=$q->fetch(PDO::FETCH_ASSOC);
smsCheck(is_array($loserState) && $loserState['status']==='pending'
    && (int)$loserState['failed_attempts']===2,
    'second loser replay is durably charged without consumed grant');

/**
 * Native callback failure AFTER changing replay SQL must roll back BOTH
 * tables. Still synthetic; positive Bitrix Otp persist/rollback is untested.
 */
$rollbackId=hash('sha256','ci:v5:totp-native-failure-rollback');
smsChallenge($db,$rollbackId);
$q=$db->prepare('UPDATE ev_v5_auth_challenges SET kind=? WHERE challenge_id=?');
$q->execute(['totp',$rollbackId]);
$failAfterWrite=new class implements V5LockedChallengeNativeFactorPort {
    public function currentFactorEligibleWithLocks(
        PDO $db,int $userId,ChallengeKind $kind,
        string $epoch,?string $stamp
    ):bool {
        return $db->inTransaction() && $userId===41
            && $kind===ChallengeKind::TOTP && $epoch===SMS_EPOCH && $stamp===null;
    }
    public function consumeNativeTotpWithLocks(PDO $db,int $userId,string $code):bool {
        $u=$db->prepare('UPDATE ev_v5_ci_totp_replay_guard '
            .'SET LAST_TIMECODE=2 WHERE USER_ID=?');
        $u->execute([$userId]);
        throw new RuntimeException('ci_synthetic_native_fail_after_write');
    }
};
smsDeny(fn()=>(new V5MySqlChallengeConsumption(
    $db,$failAfterWrite,BIND_KEY,SMS_HMAC_KEY
))->verifyAndConsume($rollbackId,'123456',DEVICE_ID,'ios'),
    'unexpected native verifier failure denies entire TOTP challenge transaction');
$q=$db->query('SELECT LAST_TIMECODE FROM ev_v5_ci_totp_replay_guard '
    .'WHERE USER_ID=41');
smsCheck((int)$q->fetchColumn()===1,
    'native-style replay write rolled back when factor fails before COMMIT');
$q=$db->prepare('SELECT status,failed_attempts FROM ev_v5_auth_challenges '
    .'WHERE challenge_id=?');
$q->execute([$rollbackId]);$rolledBackChallenge=$q->fetch(PDO::FETCH_ASSOC);
smsCheck(is_array($rolledBackChallenge)
    && $rolledBackChallenge['status']==='pending'
    && (int)$rolledBackChallenge['failed_attempts']===0,
    'failed native factor granted nothing and made no partial challenge write');
echo "PASS: real disposable InnoDB distinct-challenge same-timestep "
    ."replay race and native-callback rollback (synthetic factor ONLY)\n";
echo "REAL_NATIVE_BITRIX_TOTP=NOT_TESTED\n";




/**
 * Real DB PAID-SMS pre-dispatch gate, but ZERO actual provider requests.
 * The provider/safety port is mandatory and synthetic only in this CI test.
 * A claimed/unknown delivery is never automatically re-queued or retried.
 */
final class CiSyntheticSentReceipt implements V5SmsSentReceiptAttestationPort {
    public bool $confirmed=true;
    public int $checks=0;
    public function independentlyConfirmedSent(
        string $deliveryId,string $providerIdempotencyKey
    ):bool {
        $this->checks++;
        return $this->confirmed && preg_match('/\\A[a-f0-9]{64}\\z/D',$deliveryId)===1
            && hash_equals($deliveryId,$providerIdempotencyKey);
    }
}
final class CiSmsDispatchSafety implements V5LockedSmsDispatchSafetyPort {
    public bool $allow=true;
    public int $calls=0;
    public function stillSafeWithChallengeLocks(
        PDO $db,int $userId,string $challengeEpoch,string $verificationStamp,
        int $authorizedMaxPriceMinor,string $currency
    ):bool {
        $this->calls++;
        // SYNTHETIC contractual provider quote: any price >1200 RUB denied.
        return $this->allow && $db->inTransaction()
            && $userId===41 && $challengeEpoch===SMS_EPOCH
            && $verificationStamp===SMS_STAMP
            && $authorizedMaxPriceMinor===1200 && $currency==='RUB';
    }
}
function ciClaimRace(string $deliveryId):int {
    try {
        $connection=smsDb();
        $c=new V5MySqlSmsDispatchClaims($connection,new CiSmsDispatchSafety());
        return $c->claimQueuedOnce($deliveryId) instanceof V5ClaimedSmsForOneAttempt
            ?0:1;
    }catch(Throwable){return 2;}
}
$db=smsDb();
$disSafety=new CiSmsDispatchSafety();
$syntheticReceipt=new CiSyntheticSentReceipt();
$dispatch=new V5MySqlSmsDispatchClaims($db,$disSafety,$syntheticReceipt);
$dispatchId=str_repeat('7',64);
smsChallenge($db,$dispatchId);
$queued=smsStore($db)->reserveAndQueue(
    smsRequest($dispatchId,smsWindows(160,3600))
);
smsCheck($dispatch->claimQueuedOnce('not-a-delivery')===null,
    'malformed paid-SMS delivery ID cannot start provider attempt');
smsCheck($dispatch->recordProviderOutcome('bad','bad',
    V5SmsProviderTerminalOutcome::Sent)===false,
    'malformed ack cannot certify a paid SMS as sent');
$claimed=$dispatch->claimQueuedOnce($queued->deliveryId);
smsCheck($claimed instanceof V5ClaimedSmsForOneAttempt
    && $claimed->providerIdempotencyKey===$queued->deliveryId
    && $claimed->encryptedEnvelope!=='',
    'real MariaDB worker claims encrypted provider-idempotent SMS once');
$q=$db->prepare('SELECT status FROM ev_v5_sms_outbox WHERE delivery_id=?');
$q->execute([$queued->deliveryId]);
smsCheck($q->fetchColumn()==='claimed',
    'claim writes durable claimed state BEFORE any paid provider call');
smsCheck($dispatch->claimQueuedOnce($queued->deliveryId)===null,
    'duplicate worker cannot claim already claimed message');
$code=ciSmsCode($db,$dispatchId);
smsCheck(ciConsumer($db)->verifyAndConsume(
    $dispatchId,$code,DEVICE_ID,'ios')===null,
    'claimed-but-not-sent SMS cannot authorize guest login');
smsCheck($dispatch->recordProviderOutcome($queued->deliveryId,
    str_repeat('0',64),V5SmsProviderTerminalOutcome::Sent)===false,
    'forged provider idempotency key cannot acknowledge delivery');
$noReceipt=new V5MySqlSmsDispatchClaims($db,$disSafety);
smsCheck($noReceipt->recordProviderOutcome(
    $queued->deliveryId,$claimed->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Sent)===false,
    'missing independently attested provider receipt cannot mark SMS sent');
$syntheticReceipt->confirmed=false;
smsCheck($dispatch->recordProviderOutcome(
    $queued->deliveryId,$claimed->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Sent)===false,
    'native false provider receipt cannot authorize login');
$syntheticReceipt->confirmed=true;
smsCheck($dispatch->recordProviderOutcome(
    $queued->deliveryId,$claimed->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Sent)===true,
    'positive synthetic provider receipt changes claimed into sent ONCE');
smsCheck($syntheticReceipt->checks>=2,
    'provider receipt verification required before sent transition');
smsCheck($dispatch->recordProviderOutcome(
    $queued->deliveryId,$claimed->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Sent)===false,
    'duplicate provider acknowledgement cannot mutate sent delivery twice');
smsCheck($dispatch->claimQueuedOnce($queued->deliveryId)===null,
    'already sent paid delivery cannot be reclaimed/retried');
smsCheck(ciConsumer($db)->verifyAndConsume(
    $dispatchId,$code,DEVICE_ID,'ios') instanceof ConsumedLoginChallenge,
    'real InnoDB challenge now permits code only after positive sent outcome');

$unknownId=str_repeat('8',64);
smsChallenge($db,$unknownId);
$unknownQueued=smsStore($db)->reserveAndQueue(
    smsRequest($unknownId,smsWindows(176,3600))
);
$unknownClaimed=$dispatch->claimQueuedOnce($unknownQueued->deliveryId);
smsCheck($unknownClaimed instanceof V5ClaimedSmsForOneAttempt,
    'ambiguous-provider fixture claimed once');
smsCheck($dispatch->recordProviderOutcome(
    $unknownQueued->deliveryId,$unknownClaimed->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Unknown)===true,
    'uncertain provider result persists UNKNOWN, not sent');
smsCheck($dispatch->claimQueuedOnce($unknownQueued->deliveryId)===null,
    'UNKNOWN outcome cannot automatically trigger another provider charge');
smsCheck($dispatch->recordProviderOutcome(
    $unknownQueued->deliveryId,$unknownClaimed->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Sent)===false,
    'late fabricated sent acknowledgement cannot overwrite UNKNOWN');
smsCheck(ciConsumer($db)->verifyAndConsume(
    $unknownId,ciSmsCode($db,$unknownId),DEVICE_ID,'ios')===null,
    'ambiguous paid SMS cannot be used as a verified login factor');

$noNativeId=str_repeat('9',64);
smsChallenge($db,$noNativeId);
$noNativeQueue=smsStore($db)->reserveAndQueue(
    smsRequest($noNativeId,smsWindows(192,3600))
);
// A rate change between reservation and claiming must be detected
// BEFORE any paid gateway is attempted. The site proof/quote is mandatory.
$q=$db->prepare('UPDATE ev_v5_sms_outbox SET max_price_minor=? '
    .'WHERE delivery_id=?');
$q->execute([5000,$noNativeQueue->deliveryId]);
smsCheck($dispatch->claimQueuedOnce($noNativeQueue->deliveryId)===null,
    'changed provider price above original ceiling prevents SMS claim');
$q->execute([1200,$noNativeQueue->deliveryId]);
$disSafety->allow=false;
smsCheck($dispatch->claimQueuedOnce($noNativeQueue->deliveryId)===null,
    'missing native phone/epoch safety blocks paid send before claim');
$q=$db->prepare('SELECT status FROM ev_v5_sms_outbox WHERE delivery_id=?');
$q->execute([$noNativeQueue->deliveryId]);
smsCheck($q->fetchColumn()==='queued',
    'native callback refusal left paid outbox unclaimed/unspent');
$disSafety->allow=true;
$failedClaim=$dispatch->claimQueuedOnce($noNativeQueue->deliveryId);
smsCheck($failedClaim instanceof V5ClaimedSmsForOneAttempt,
    'native verification restoration enables one trusted attempt');
smsCheck($dispatch->recordProviderOutcome(
    $noNativeQueue->deliveryId,$failedClaim->providerIdempotencyKey,
    V5SmsProviderTerminalOutcome::Failed)===true,
    'explicit no-send/provider failure persisted as FAILED');
smsCheck($dispatch->claimQueuedOnce($noNativeQueue->deliveryId)===null,
    'failed provider outcome cannot retry same paid delivery');

$expiredId=str_repeat('e',64);
smsChallenge($db,$expiredId);
$expiredQueued=smsStore($db)->reserveAndQueue(
    smsRequest($expiredId,smsWindows(208,3600))
);
$q=$db->prepare('UPDATE ev_v5_auth_challenges SET expires_at=UNIX_TIMESTAMP()-1 '
    .'WHERE challenge_id=?');
$q->execute([$expiredId]);
smsCheck($dispatch->claimQueuedOnce($expiredQueued->deliveryId)===null,
    'expired SMS challenge cannot be dispatched even if already queued');

$claimRaceId=str_repeat('f',64);
smsChallenge($db,$claimRaceId);
$raceQueued=smsStore($db)->reserveAndQueue(
    smsRequest($claimRaceId,smsWindows(224,3600))
);
$raceDeliveryId=$raceQueued->deliveryId;
unset($db,$dispatch,$disSafety,$claimed,$unknownClaimed,$failedClaim);
gc_collect_cycles();
$pid=pcntl_fork();
if($pid===-1)throw new RuntimeException('ephemeral SMS claim fork unavailable');
if($pid===0)exit(ciClaimRace($raceDeliveryId));
$parent=ciClaimRace($raceDeliveryId);
$waited=pcntl_waitpid($pid,$status);
$child=pcntl_wifexited($status)?pcntl_wexitstatus($status):2;
smsCheck($waited===$pid && in_array($parent,[0,1],true)
    && in_array($child,[0,1],true)
    && (($parent===0?1:0)+($child===0?1:0))===1,
    'two real MariaDB workers race same paid SMS: EXACTLY ONE CLAIMED');
$db=smsDb();
$q=$db->prepare('SELECT status FROM ev_v5_sms_outbox WHERE delivery_id=?');
$q->execute([$raceDeliveryId]);
smsCheck($q->fetchColumn()==='claimed',
    'concurrent paid-SMS claim commits exactly one attempt, no duplicate charge');
smsCheck((new V5MySqlSmsDispatchClaims($db,new CiSmsDispatchSafety()))
    ->claimQueuedOnce($raceDeliveryId)===null,
    'claimed after crash stays claimed and cannot auto-retry uncertain send');

echo "PASS: real ephemeral MariaDB SMS quota/outbox ($checks assertions)\n";
echo "MODE=GITHUB_EPHEMERAL_CI_ONLY\nREAL_MARIADB_INNODB=YES\n";
echo "TWO_PROCESS_SMS_FIRST_SEND_RACE=YES\n";
echo "ENCRYPTED_OUTBOX_ONLY=YES\nACTUAL_SMS_SENT=NO\n";
echo "REAL_BITRIX_BOOTSTRAP=NO\nNATIVE_PHONE_WRITER_ATTESTED=NO\n";
echo "PRODUCTION_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
