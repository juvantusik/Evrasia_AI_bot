<?php
declare(strict_types=1);

// Offline contract: NO real DB connection, no Bitrix bootstrapping,
// no synthetic accounts, Redis, SMS or email. Challenge IDs are opaque
// invalid/valid-shaped test values, never real account identifiers.
require_once __DIR__ . '/../src/V5ExistingWebsiteSmsRecoveryAdapter.php';

use Evrasia\Samzaberu\V5\{
    AuthenticationRejected,ChallengeKind,PendingLoginChallenge,
    V5ExistingWebsiteSmsRecoveryAdapter,V5ExistingWebsiteSmsNativeTransitionPort,
    V5PinnedWebsiteSmsSourceProof,V5WebsiteSmsSourceProofPort
};

final class SiteSmsProofNoRuntime implements V5WebsiteSmsSourceProofPort {
    public ?bool $ready = null;
    public int $calls = 0;
    public function exactPinnedSiteSourcesAvailable(): ?bool {
        $this->calls++;
        return $this->ready;
    }
}
final class SiteSmsNativeNoRuntime implements V5ExistingWebsiteSmsNativeTransitionPort {
    public ?bool $ready = null;
    public int $attestCalls = 0;
    public int $requestCalls = 0;
    public bool $throw = false;
    public bool $sameId = false;
    public bool $wrongFactor = false;
    public ?bool $confirmed = null;
    public bool $receiptThrows = false;
    public int $receiptReads = 0;
    public ?PDO $expectedDb = null;
    public function verifiedSameBitrixMobileRecovery(PDO $db): ?bool {
        $this->attestCalls++;
        return $db === $this->expectedDb ? $this->ready : false;
    }
    public function transitionAcceptedEmailToNativeSms(
        PDO $db, string $emailChallengeId, string $deviceId, string $platform
    ): PendingLoginChallenge {
        $this->requestCalls++;
        if ($db !== $this->expectedDb || $this->throw) {
            throw new RuntimeException('private_sms_transport_failure');
        }
        return new PendingLoginChallenge(
            $this->sameId ? $emailChallengeId : str_repeat('b',64),
            $this->wrongFactor ? ChallengeKind::EMAIL : ChallengeKind::SMS,
            90
        );
    }
    public function confirmedCurrentSmsSend(
        PDO $db, string $emailChallengeId, string $smsChallengeId,
        string $deviceId, string $platform
    ): ?bool {
        $this->receiptReads++;
        if ($this->receiptThrows) throw new RuntimeException('no_receipt_authority');
        if ($db !== $this->expectedDb
            || $emailChallengeId !== str_repeat('a',64)
            || $smsChallengeId !== str_repeat('b',64)
            || $deviceId !== 'sz_'.str_repeat('c',64)
            || $platform !== 'ios') return false;
        return $this->confirmed;
    }
}
$checks=0;
$check=static function(bool $condition,string $message) use (&$checks):void {
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $checks++;
};
$denied=static function(callable $action,string $message) use ($check):void {
    try {$action();} catch(AuthenticationRejected $ex) {
        $check($ex->getMessage()==='challenge_unavailable',$message);
        return;
    }
    $check(false,$message);
};
$db=new class extends PDO { public function __construct() {} };
$foreignDb=new class extends PDO { public function __construct() {} };
$sources=new SiteSmsProofNoRuntime();
$native=new SiteSmsNativeNoRuntime();
$native->expectedDb=$db;
$adapter=new V5ExistingWebsiteSmsRecoveryAdapter($db,$sources,$native);
$cid=str_repeat('a',64);
$device='sz_'.str_repeat('c',64);

$check($adapter->verifiedExistingSiteRecovery($db) === false,
    'missing website pin evidence denies');
$check($adapter->verifiedExistingSiteRecovery($foreignDb) === false,
    'different physical PDO is rejected before site or provider callbacks');
$check($sources->calls===1 && $native->attestCalls===0,
    'no call to native attestor when website source is unavailable');
$sources->ready=true;
$check($adapter->verifiedExistingSiteRecovery($db)===false,
    'source SHA alone does not allow SMS');
$native->ready=true;
$check($adapter->verifiedExistingSiteRecovery($db)===true,
    'same PDO, current sources and native proof are all required');
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'provider returning a ticket is NOT proof of SMS send');
$check($native->requestCalls===1 && $native->receiptReads===1,
    'missing send attestation denies before public SMS ticket');
$native->confirmed=false;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'explicitly unconfirmed SMS provider receipt denies');
$native->receiptThrows=true;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'unknown provider receipt failure is never accepted');
$native->receiptThrows=false;
$native->confirmed=true;
$ticket=$adapter->requestSmsInsteadOfEmail($cid,$device,'ios');
$check($ticket->kind===ChallengeKind::SMS
    && $ticket->challengeId!==$cid && $native->requestCalls===4
    && $native->receiptReads===4,
    'only independently confirmed CURRENT SMS challenge returns a ticket');
$denied(fn()=>$adapter->requestSmsInsteadOfEmail('wrong',$device,'ios'),
    'malformed email challenge denied');
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,'cookie','ios'),
    'malformed device denied');
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'web'),
    'wrong platform denied');
$check($native->requestCalls===4,'invalid inputs never reach site-native SMS');
$native->sameId=true;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'new mobile SMS ID cannot be same as email ID');
$native->sameId=false;
$native->wrongFactor=true;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'cannot relabel an email pending challenge as SMS');
$native->wrongFactor=false;
$receiptBefore=$native->receiptReads;
$native->sameId=true;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'same-id ticket still denied without receipt lookup');
$native->sameId=false;
$check($native->receiptReads===$receiptBefore,
    'non-SMS or reused IDs do not invoke receipt authority');
$native->throw=true;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'private SMS provider errors never reach caller');
$native->throw=false;
$sources->ready=false;
$before=$native->requestCalls;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'source fingerprint changed: fail closed at request time');
$check($native->requestCalls===$before,
    'source drift prevents paid SMS calls after previous successful verification');
$sources->ready=true;
$native->ready=false;
$denied(fn()=>$adapter->requestSmsInsteadOfEmail($cid,$device,'ios'),
    'native readiness withdrawn: fail closed at request time');
$check($native->requestCalls===$before,
    'native readiness loss prevents paid SMS calls');
$check((new V5PinnedWebsiteSmsSourceProof())->exactPinnedSiteSourcesAvailable()!==true,
    'production-only SHA pin does not grant SMS in CI without installed website');

printf("PASS: V5 existing-website SMS source pin and same-PDO native adapter (%d assertions)\n",$checks);
echo "MODE=SOURCE_ONLY\nDATABASE_CONNECTION=NO\nBITRIX_BOOTSTRAP=NO\n";
echo "SMS_SENT=NO\nREDIS_CONNECTION=NO\nPRODUCTION_CODE_WRITE=NO\n";
echo "NATIVE_PROVIDER_INSTALLED=NO\nV5_AUTH_PRODUCTION=OFF\n";
