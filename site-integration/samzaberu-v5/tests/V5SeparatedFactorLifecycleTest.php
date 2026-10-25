<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SeparatedFactorLifecycle.php';

use Evrasia\Samzaberu\V5\{
    V5SeparatedFactorLifecycle,V5PasswordBoundChallengeLifecyclePort,
    PasswordVerifiedAccount,PendingLoginChallenge,ChallengeKind,
    V5LoginWorkflow,AuthenticationRejected,
    V5PasswordProofPort,V5TrustProofPort,MobileTrustAssessment,
    V5StrongSessionPort,AuthenticatedV5Session,ConsumedLoginChallenge,
    V5MySqlChallengeHttpPort,V5MySqlChallengeConsumption,V5LockedChallengeNativeFactorPort
};

const DEVICE = 'sz_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const CHALLENGE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

final class CiFactorIssuer implements V5PasswordBoundChallengeLifecyclePort {
    public int $bound = 0;
    public int $resends = 0;
    public bool $fail = false;
    public ?ChallengeKind $wrongKind = null;
    public bool $wrongId = false;
    public function __construct(private ChallengeKind $kind) {}
    public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind):PendingLoginChallenge {
        throw new RuntimeException('unbound provider must not run');
    }
    public function beginBound(PasswordVerifiedAccount $account,string $login,string $password,
        string $deviceId,string $platform,ChallengeKind $kind):PendingLoginChallenge {
        $this->bound++;
        if ($this->fail) throw new RuntimeException('internal provider secret');
        return new PendingLoginChallenge(
            $this->wrongId ? str_repeat('d',64) : CHALLENGE,
            $this->wrongKind ?? $this->kind, 300
        );
    }
    public function resendSms(string $challengeId,string $deviceId,string $platform):PendingLoginChallenge {
        $this->resends++;
        if ($this->fail) throw new RuntimeException('internal provider secret');
        return new PendingLoginChallenge(
            $this->wrongId ? str_repeat('d',64) : $challengeId,
            $this->wrongKind ?? $this->kind, 240
        );
    }
}
final class CiFactorPassword implements V5PasswordProofPort {
    public function __construct(public bool $totp) {}
    public function verifyWithoutLogin(string $login,string $password):?PasswordVerifiedAccount {
        return new PasswordVerifiedAccount(41,true,$this->totp);
    }
}
final class CiFactorTrust implements V5TrustProofPort {
    public function evaluate(int $userId,string $deviceId,string $platform,string $trustToken):MobileTrustAssessment {
        return new MobileTrustAssessment(false,false);
    }
}
final class CiFactorSessions implements V5StrongSessionPort {
    public int $calls=0;
    public function afterTrustedDevice(PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken):AuthenticatedV5Session {
        $this->calls++;throw new RuntimeException('no sessions in routing fixture');
    }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant):AuthenticatedV5Session {
        $this->calls++;throw new RuntimeException('no sessions in routing fixture');
    }
}

$passed=0;
function okFactor(bool $condition,string $message):void {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $passed++;
}
function rejectFactor(callable $run,string $message):void {
    try {
        $run();
    } catch (AuthenticationRejected $e) {
        okFactor($e->getMessage()==='challenge_unavailable',$message);
        return;
    }
    throw new RuntimeException('FAIL: '.$message);
}

$totp = new CiFactorIssuer(ChallengeKind::TOTP);
$sms = new CiFactorIssuer(ChallengeKind::SMS);
$router = new V5SeparatedFactorLifecycle($totp,$sms);
$tAccount = new PasswordVerifiedAccount(41,true,true);
$sAccount = new PasswordVerifiedAccount(41,true,false);
rejectFactor(fn()=>$router->beginBound($sAccount,'login','password',DEVICE,'ios',ChallengeKind::EMAIL),
    'email cannot silently use the SMS fallback provider');
okFactor($router instanceof V5PasswordBoundChallengeLifecyclePort,'typed bound lifecycle');
okFactor(!($router instanceof Evrasia\Samzaberu\V5\V5PublicVerifiedFactorLifecyclePort),
    'staged router never attests public native factor readiness');
rejectFactor(fn()=>$router->begin(41,DEVICE,'ios',ChallengeKind::SMS),'unbound SMS start denied');
rejectFactor(fn()=>$router->begin(41,DEVICE,'ios',ChallengeKind::EMAIL),'unbound email start denied');
rejectFactor(fn()=>$router->begin(41,DEVICE,'ios',ChallengeKind::TOTP),'unbound TOTP start denied');

$t=$router->beginBound($tAccount,'login','password',DEVICE,'ios',ChallengeKind::TOTP);
okFactor($t->kind===ChallengeKind::TOTP && $totp->bound===1 && $sms->bound===0,
    'native TOTP account uses only TOTP issuer');
$s=$router->beginBound($sAccount,'login','password',DEVICE,'ios',ChallengeKind::SMS);
okFactor($s->kind===ChallengeKind::SMS && $totp->bound===1 && $sms->bound===1,
    'SMS-only account uses only SMS issuer');
rejectFactor(fn()=>$router->beginBound($tAccount,'login','password',DEVICE,'ios',ChallengeKind::SMS),
    'TOTP enrollment cannot downgrade to SMS');
rejectFactor(fn()=>$router->beginBound($sAccount,'login','password',DEVICE,'ios',ChallengeKind::TOTP),
    'non-TOTP account cannot forge TOTP branch');
okFactor($totp->bound===1 && $sms->bound===1,'invalid factor selection never calls provider');
rejectFactor(fn()=>$router->beginBound($sAccount,'','password',DEVICE,'ios',ChallengeKind::SMS),
    'blank login rejected before SMS');
rejectFactor(fn()=>$router->beginBound($sAccount,'login','password','bad-device','ios',ChallengeKind::SMS),
    'invalid installation rejected before SMS');
rejectFactor(fn()=>$router->beginBound(new PasswordVerifiedAccount(41,false,false),
    'login','password',DEVICE,'ios',ChallengeKind::SMS),'account veto before SMS');

$totp->fail=true;
rejectFactor(fn()=>$router->beginBound($tAccount,'login','password',DEVICE,'ios',ChallengeKind::TOTP),
    'TOTP provider fault fails closed without SMS downgrade');
okFactor($sms->bound===1,'failing TOTP never invokes SMS provider');
$totp->fail=false;$totp->wrongKind=ChallengeKind::SMS;
rejectFactor(fn()=>$router->beginBound($tAccount,'login','password',DEVICE,'ios',ChallengeKind::TOTP),
    'provider cannot relabel TOTP challenge as SMS');
$totp->wrongKind=null;
$sms->wrongKind=ChallengeKind::TOTP;
rejectFactor(fn()=>$router->beginBound($sAccount,'login','password',DEVICE,'ios',ChallengeKind::SMS),
    'provider cannot relabel SMS challenge as TOTP');
$sms->wrongKind=null;

$resend=$router->resendSms(CHALLENGE,DEVICE,'ios');
okFactor($resend->kind===ChallengeKind::SMS && $sms->resends===1 && $totp->resends===0,
    'resend routes only through SMS provider');
$sms->wrongId=true;
rejectFactor(fn()=>$router->resendSms(CHALLENGE,DEVICE,'ios'),
    'SMS provider cannot redirect resend to another challenge');
$sms->wrongId=false;$sms->wrongKind=ChallengeKind::TOTP;
rejectFactor(fn()=>$router->resendSms(CHALLENGE,DEVICE,'ios'),
    'SMS resend cannot return a TOTP ticket');
$sms->wrongKind=null;$sms->fail=true;
rejectFactor(fn()=>$router->resendSms(CHALLENGE,DEVICE,'ios'),
    'resend provider failure denied without leaking details');
$sms->fail=false;
rejectFactor(fn()=>$router->resendSms('bad-id',DEVICE,'ios'),'invalid resend ID denied');
rejectFactor(fn()=>$router->resendSms(CHALLENGE,DEVICE,'windows'),'invalid platform denied');

// Exercise the actual existing V5 HTTP challenge bridge. This is an
// unconnected PDO fixture: no query, network, or Bitrix bootstrap can run.
final class CiNoConnection extends PDO {
    public function __construct() {}
}
final class CiNoNativeFactor implements V5LockedChallengeNativeFactorPort {
    public function currentFactorEligibleWithLocks(PDO $db,int $userId,ChallengeKind $kind,
        string $challengeAuthEpoch,?string $verifiedPhoneStamp):bool {return false;}
    public function consumeNativeTotpWithLocks(PDO $db,int $userId,string $code):bool {return false;}
}
$unconnected = new V5MySqlChallengeConsumption(
    new CiNoConnection(),new CiNoNativeFactor(),str_repeat('x',40),str_repeat('y',40)
);
$httpPort = new V5MySqlChallengeHttpPort($unconnected,$router);
$session = new CiFactorSessions();
$proof = new CiFactorPassword(true);
$workflow = new V5LoginWorkflow($proof,new CiFactorTrust(),$httpPort,$session);
$ticket=$workflow->signIn('login','password',DEVICE,'ios');
okFactor($ticket instanceof PendingLoginChallenge && $ticket->kind===ChallengeKind::TOTP,
    'existing V5 login workflow chooses native TOTP path');
$proof->totp=false;
$priorSmsCalls=$sms->bound;
rejectFactor(fn()=>$workflow->signIn('login','password',DEVICE,'ios'),
    'email-first branch fails closed without installed mobile email lifecycle');
okFactor($sms->bound===$priorSmsCalls,
    'missing email issuer never silently calls paid SMS provider');
okFactor($session->calls===0,'no bearer tokens or strong session issued by routing');
try {
    new V5SeparatedFactorLifecycle($sms,$sms);
    throw new RuntimeException('FAIL: same provider reused');
} catch (InvalidArgumentException) {
    okFactor(true,'factor providers must be distinct objects');
}

printf("PASS: V5 separated TOTP/SMS routing with email-first fail-closed (%d assertions)\n",$passed);
echo "MODE=CI_OFFLINE_ONLY\nREAL_SMS_SENT=NO\nREAL_BITRIX_BOOTSTRAP=NO\n";
echo "PRODUCTION_CODE_WRITE=NO\nAPI_V4_CHANGED=NO\nV5_PUBLIC_AUTH_ENABLED=NO\n";
