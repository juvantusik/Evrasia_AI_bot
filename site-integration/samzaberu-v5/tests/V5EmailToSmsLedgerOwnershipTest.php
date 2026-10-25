<?php
declare(strict_types=1);

/**
 * Pure PHP guard tests. The email existence lookup is an injected port,
 * never a real database request. No users, SMS, site bootstrap or Redis.
 */
require_once __DIR__ . '/../src/V5MySqlChallengeHttpPort.php';

use Evrasia\Samzaberu\V5\{
    AuthenticationRejected,ChallengeKind,ConsumedLoginChallenge,
    PendingLoginChallenge,V5ExplicitSmsRecoveryPort,V5EmailChallengeVerificationPort,
    V5LockedChallengeNativeFactorPort,V5MySqlChallengeConsumption,V5MySqlChallengeHttpPort
};

final class FakeEmailLedgerOwnership implements V5EmailChallengeVerificationPort {
    public bool $owned=false;
    public bool $throw=false;
    public int $reads=0;
    public function ownsEmailChallenge(string $challengeId):bool {
        $this->reads++;
        if ($this->throw) throw new RuntimeException('email_ledger_unavailable');
        return $this->owned;
    }
    public function verifyAndConsume(
        string $challengeId,string $code,string $deviceId,string $platform
    ):?ConsumedLoginChallenge {
        throw new RuntimeException('not_a_login_test');
    }
}
final class FakePaidSmsRecovery implements V5ExplicitSmsRecoveryPort {
    public int $attempts=0;
    public function requestSmsInsteadOfEmail(
        string $emailChallengeId,string $deviceId,string $platform
    ):PendingLoginChallenge {
        $this->attempts++;
        return new PendingLoginChallenge(str_repeat('b',64),ChallengeKind::SMS,90);
    }
}
$db=new class extends PDO { public function __construct(){} };
$native=new class implements V5LockedChallengeNativeFactorPort {
    public function currentFactorEligibleWithLocks(
        PDO $db,int $userId,ChallengeKind $kind,string $challengeAuthEpoch,
        ?string $verifiedPhoneStamp
    ):bool { throw new RuntimeException('never_native'); }
    public function consumeNativeTotpWithLocks(
        PDO $db,int $userId,string $code
    ):bool { throw new RuntimeException('never_native'); }
};
$consume=new V5MySqlChallengeConsumption(
    $db,$native,str_repeat('c',40),str_repeat('d',40),false
);
$email=new FakeEmailLedgerOwnership();
$sms=new FakePaidSmsRecovery();
$port=new V5MySqlChallengeHttpPort($consume,null,$email,$sms);
$cid=str_repeat('a',64);
$device='sz_'.str_repeat('e',64);
$checks=0;
$check=static function(bool $value,string $label) use (&$checks):void{
    if (!$value) throw new RuntimeException('FAIL: '.$label);
    $checks++;
};
$deny=static function(callable $f,string $label) use ($check):void{
    try {$f();} catch(AuthenticationRejected) {$check(true,$label);return;}
    $check(false,$label);
};
$deny(
    fn()=>$port->requestSmsInsteadOfEmail('wrong',$device,'ios'),
    'bad challenge ID must not reach email lookup or SMS'
);
$check($email->reads===0 && $sms->attempts===0,
    'invalid ID cannot consume resources');
$deny(
    fn()=>$port->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'unowned email challenge denied'
);
$check($email->reads===1 && $sms->attempts===0,
    'random/SMS/TOTP IDs cannot trigger paid SMS');
$email->throw=true;
try {
    $port->requestSmsInsteadOfEmail($cid,$device,'ios');
    throw new LogicException('email exception should fail');
} catch(RuntimeException $e) {
    $check($e->getMessage()==='email_ledger_unavailable',
        'ledger failure propagates; host HTTP boundary masks to 503');
}
$check($sms->attempts===0,'ledger outage cannot trigger paid SMS');
$email->throw=false;
$email->owned=true;
$ticket=$port->requestSmsInsteadOfEmail($cid,$device,'ios');
$check($ticket->kind===ChallengeKind::SMS
    && $ticket->challengeId!==$cid
    && $sms->attempts===1,
    'existing email ID is merely preflight and delegates to native site proof');
$unbound=new V5MySqlChallengeHttpPort($consume,null,$email,null);
$deny(
    fn()=>$unbound->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'missing private site SMS provider always denies'
);
$check($sms->attempts===1,'missing provider cannot send');
printf("PASS: email-ledger ownership before site SMS (%d assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nREAL_DATABASE_CONNECTION=NO\n";
echo "REDIS_CONNECTION=NO\nSMS_SENT=NO\nEMAIL_SENT=NO\n";
echo "SYNTHETIC_USERS=NO\nV5_PRODUCTION_AUTH=OFF\n";
