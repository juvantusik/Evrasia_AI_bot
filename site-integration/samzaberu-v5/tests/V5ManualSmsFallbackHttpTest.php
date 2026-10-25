<?php
declare(strict_types=1);

// Offline V5 HTTP boundary tests; no Bitrix bootstrap, DB/Redis connections,
// account fixtures, mobile clients, phone numbers or SMS/email delivery.
require_once __DIR__ . '/../src/V5HttpTransportBoundary.php';

use Evrasia\Samzaberu\V5\{
    V5AuthHttpHandler,V5LoginWorkflow,V5PasswordProofPort,V5TrustProofPort,
    V5AtomicChallengePort,V5ExplicitSmsRecoveryPort,V5StrongSessionPort,
    V5HttpTransportBoundary,V5StagedHttpRequest,ChallengeKind,
    PendingLoginChallenge,ConsumedLoginChallenge,AuthenticatedV5Session,
    PasswordVerifiedAccount,MobileTrustAssessment
};

const EMAIL_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SMS_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
const DEVICE = 'sz_cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

final class SmsManualDenyPasswords implements V5PasswordProofPort {
    public int $calls = 0;
    public function verifyWithoutLogin(string $login,string $password): ?PasswordVerifiedAccount {
        $this->calls++;
        return null;
    }
}
final class SmsManualDenyTrust implements V5TrustProofPort {
    public function evaluate(int $userId,string $deviceId,string $platform,string $trustToken): MobileTrustAssessment {
        throw new RuntimeException('trust_must_not_run');
    }
}
final class SmsManualDenySessions implements V5StrongSessionPort {
    public int $calls=0;
    public function afterTrustedDevice(PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken): AuthenticatedV5Session {
        $this->calls++;
        throw new RuntimeException('jwt_must_not_run');
    }
    public function afterConsumedChallenge(ConsumedLoginChallenge $grant): AuthenticatedV5Session {
        $this->calls++;
        throw new RuntimeException('jwt_must_not_run');
    }
}
class SmsManualNoRecovery implements V5AtomicChallengePort {
    public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind):PendingLoginChallenge {
        throw new RuntimeException('begin_must_not_run');
    }
    public function verifyAndConsume(string $challengeId,string $code,string $deviceId,string $platform):?ConsumedLoginChallenge {
        throw new RuntimeException('consume_must_not_run');
    }
    public function resendSms(string $challengeId,string $deviceId,string $platform):PendingLoginChallenge {
        throw new RuntimeException('resend_must_not_run');
    }
}
final class SmsManualBoundPort extends SmsManualNoRecovery implements V5ExplicitSmsRecoveryPort {
    public int $calls=0;
    public ChallengeKind $kind=ChallengeKind::SMS;
    public string $newId=SMS_ID;
    public bool $fail=false;
    public function requestSmsInsteadOfEmail(string $emailChallengeId,string $deviceId,string $platform):PendingLoginChallenge {
        $this->calls++;
        if ($this->fail) throw new RuntimeException('private_native_sms_failure');
        return new PendingLoginChallenge($this->newId,$this->kind,120);
    }
}

$pass=0;
function smsCheck(bool $yes,string $label):void {
    global $pass;
    if (!$yes) throw new RuntimeException('FAIL: '.$label);
    $pass++;
}
function smsRequest(string $method='POST',string $path='/api/v5/auth/challenge/use-sms',
    ?array $payload=null,bool $https=true,string $type='application/json'):V5StagedHttpRequest {
    $json=json_encode($payload ?? [
        'challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios'
    ],JSON_THROW_ON_ERROR);
    return new V5StagedHttpRequest($method,$path,$https,$type,$json,(string)strlen($json));
}
function smsTransport(V5AtomicChallengePort $port,
    SmsManualDenyPasswords $passwords,SmsManualDenySessions $sessions):V5HttpTransportBoundary {
    return new V5HttpTransportBoundary(new V5AuthHttpHandler(
        new V5LoginWorkflow($passwords,new SmsManualDenyTrust(),$port,$sessions)
    ));
}

$disabled=(new V5HttpTransportBoundary())->dispatch(smsRequest());
smsCheck($disabled->status===503,'absent HTTP handler is still disabled');
$passPort=new SmsManualDenyPasswords();
$sessions=new SmsManualDenySessions();
$noNative=smsTransport(new SmsManualNoRecovery(),$passPort,$sessions);
$r=$noNative->dispatch(smsRequest());
smsCheck($r->status===401 && json_decode($r->jsonBody,true)===['error'=>'authentication_failed'],
    'missing verified SMS recovery port fails closed');
smsCheck($passPort->calls===0 && $sessions->calls===0,
    'manual recovery cannot execute ordinary password or JWT issuance');

$provider=new SmsManualBoundPort();
$transport=smsTransport($provider,$passPort,$sessions);
$r=$transport->dispatch(smsRequest());
$body=json_decode($r->jsonBody,true);
smsCheck($r->status===202
    && ($body['status']??null)==='challenge_pending'
    && ($body['challenge_id']??null)===SMS_ID
    && ($body['challenge_type']??null)==='sms'
    && ($body['expires_in']??null)===120,
    'explicit request returns separately bound SMS pending contract');
smsCheck(!isset($body['access_token'],$body['refresh_token'])
    && !isset($body['phone']) && !isset($body['email']),
    'pending SMS response contains no credentials or destination');
smsCheck($provider->calls===1 && $passPort->calls===0 && $sessions->calls===0,
    'manual provider only, no normal signin, JWT or account lookup');

foreach ([
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','phone'=>'client-value'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','user_id'=>123],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','email'=>'client-value'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','method'=>'sms'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','password'=>'client-value'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','trust_token'=>'client-value'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'ios','code'=>'123456'],
    ['challenge_id'=>17,'device_id'=>DEVICE,'platform'=>'ios'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>false],
] as $bad) {
    $r=$transport->dispatch(smsRequest(payload:$bad));
    smsCheck($r->status===400,'forbid client-selected SMS destination, factors and invalid types');
}
smsCheck($provider->calls===1,'invalid body never reaches SMS recovery');
foreach ([
    ['challenge_id'=>'wrong','device_id'=>DEVICE,'platform'=>'ios'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>'not-mobile','platform'=>'ios'],
    ['challenge_id'=>EMAIL_ID,'device_id'=>DEVICE,'platform'=>'windows'],
] as $bad) {
    $r=$transport->dispatch(smsRequest(payload:$bad));
    smsCheck($r->status===401,'malformed challenge/device denied without SMS recovery');
}
smsCheck($provider->calls===1,'invalid challenge/device never triggers provider');

$provider->kind=ChallengeKind::EMAIL;
smsCheck($transport->dispatch(smsRequest())->status===401,
    'native provider cannot relabel SMS fallback as email');
$provider->kind=ChallengeKind::SMS;
$provider->newId=EMAIL_ID;
smsCheck($transport->dispatch(smsRequest())->status===401,
    'new SMS ticket cannot collide with original email challenge ID');
$provider->newId=SMS_ID;
$provider->fail=true;
$r=$transport->dispatch(smsRequest());
smsCheck($r->status===503 && !str_contains($r->jsonBody,'private_native_sms_failure'),
    'native outage fails closed without leaking provider details');

$provider->fail=false;
smsCheck($transport->dispatch(smsRequest(method:'GET'))->status===405,
    'manual SMS endpoint accepts POST only');
smsCheck($transport->dispatch(smsRequest(path:'/api/v5/auth/challenge/use-sms?x=1'))->status===404,
    'query-string aliases rejected');
smsCheck($transport->dispatch(smsRequest(https:false))->status===403,
    'untrusted HTTPS rejected before any provider call');
smsCheck($transport->dispatch(smsRequest(type:'text/plain'))->status===415,
    'content type enforced');
smsCheck($sessions->calls===0 && $passPort->calls===0,
    'no synthetic accounts, tokens, DB, mail or SMS used in tests');
printf("PASS: manual email-to-SMS HTTP recovery boundary (%d source-only assertions)\n",$pass);
echo "MODE=SOURCE_ONLY\nDATABASE_CONNECTION=NO\n";
echo "SYNTHETIC_ACCOUNTS=NO\nEMAIL_SENT=NO\nSMS_SENT=NO\n";
echo "V4_CHANGED=NO\nV5_AUTH_PRODUCTION=OFF\n";
