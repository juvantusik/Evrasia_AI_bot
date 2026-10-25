<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5MySqlPublicComposition.php';

use Evrasia\Samzaberu\V5\{
    V5MySqlPublicComposition,V5TrustedInstalledNativeCoveragePort,
    V5PublicDependencyAttestationPort,V5PasswordProofPort,V5TrustProofPort,
    V5LockedStrongSessionProofPort,V5PublicVerifiedFactorLifecyclePort,
    V5LockedChallengeNativeFactorPort,V5LockedBonusPinReaderPort,
    V5TokenValidationPort,PasswordVerifiedAccount,MobileTrustAssessment,
    ConsumedLoginChallenge,PendingLoginChallenge,ChallengeKind,
    V5AccessJwtCodec,V5OpaqueRefreshTokens,V5HttpTransportBoundary,
    V5AttestedExplicitSmsRecoveryPort,V5StagedHttpRequest,
};

final class PublicWireDb extends PDO {
    public function __construct() {}
    public function getAttribute(int $attribute): mixed {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'mysql',
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            default => null,
        };
    }
    public function inTransaction(): bool { return false; }
}
final class PublicWireNative implements V5TrustedInstalledNativeCoveragePort {
    public ?bool $verified = null;
    public int $perUserCalls = 0;
    public function releaseVerifiedOnThisServer(PDO $db): ?bool { return $this->verified; }
    public function proofForUser(PDO $db, int $userId): ?array {
        $this->perUserCalls++;
        return null;
    }
}
final class PublicWireDependencies implements V5PublicDependencyAttestationPort {
    public ?bool $verified = null;
    public function verifiedForPublicTraffic(PDO $db): ?bool { return $this->verified; }
}
final class PublicWireDenyingPorts implements V5PasswordProofPort,V5TrustProofPort,
    V5LockedStrongSessionProofPort,V5PublicVerifiedFactorLifecyclePort,
    V5LockedChallengeNativeFactorPort,V5LockedBonusPinReaderPort,
    V5TokenValidationPort {
    public int $passwordCalls = 0;
    public int $challengeCalls = 0;
    public ?bool $publicFactorReady = null;
    public function verifiedPublicSmsAndTotpLifecycle(PDO $db): ?bool { return $this->publicFactorReady; }
    public function verifyWithoutLogin(string $login,string $password): ?PasswordVerifiedAccount {
        $this->passwordCalls++;
        return null;
    }
    public function evaluate(int $userId,string $deviceId,string $platform,string $trustToken): MobileTrustAssessment {
        return new MobileTrustAssessment(false,false);
    }
    public function verifiedTrustWithUserLock(PDO $db,PasswordVerifiedAccount $account,
        string $deviceId,string $platform,string $trustToken): bool { return false; }
    public function verifiedConsumedGrantWithUserLock(PDO $db,ConsumedLoginChallenge $grant): bool { return false; }
    public function begin(int $userId,string $deviceId,string $platform,ChallengeKind $kind): PendingLoginChallenge {
        $this->challengeCalls++; throw new RuntimeException('challenge unavailable');
    }
    public function beginBound(PasswordVerifiedAccount $account,string $login,string $password,
        string $deviceId,string $platform,ChallengeKind $kind): PendingLoginChallenge {
        $this->challengeCalls++; throw new RuntimeException('challenge unavailable');
    }
    public function resendSms(string $challengeId,string $deviceId,string $platform): PendingLoginChallenge {
        $this->challengeCalls++; throw new RuntimeException('challenge unavailable');
    }
    public function currentFactorEligibleWithLocks(PDO $db,int $userId,ChallengeKind $kind,
        string $challengeAuthEpoch,?string $verifiedPhoneStamp): bool { return false; }
    public function consumeNativeTotpWithLocks(PDO $db,int $userId,string $code): bool { return false; }
    public function readLockedBonusPin(PDO $db,int $userId): ?string { return null; }
    public function isValidAccessToken(string $accessJwt): bool { return false; }
}

$checks=0;
function publicWireCheck(bool $ok,string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    $checks++;
}
function publicWireReject(callable $callback,string $label): void {
    try { $callback(); } catch (InvalidArgumentException) {
        publicWireCheck(true,$label); return;
    }
    publicWireCheck(false,$label);
}

$db=new PublicWireDb();
$native=new PublicWireNative();
$dependencies=new PublicWireDependencies();
$ports=new PublicWireDenyingPorts();
$access=new V5AccessJwtCodec(['testkid'=>str_repeat('a',40)],'testkid');
$refresh=new V5OpaqueRefreshTokens(str_repeat('b',40));
$server=['HTTPS'=>'on','REMOTE_ADDR'=>'198.51.100.99','REQUEST_METHOD'=>'POST'];
$builder=static function (array $request) use ($db,$native,$dependencies,$ports,$access,$refresh): V5HttpTransportBoundary {
    return V5MySqlPublicComposition::build(
        $db,$request,$native,$dependencies,$ports,$ports,$ports,$ports,
        $ports,$ports,$ports,$access,$refresh,str_repeat('c',40),str_repeat('d',40)
    );
};

publicWireReject(fn()=>$builder($server),'no installed native proof denies public assembly');
$native->verified=true;
publicWireReject(fn()=>$builder($server),'native proof alone does not assemble public transport');
$dependencies->verified=true;
publicWireReject(fn()=>$builder(array_replace($server,['HTTPS'=>'off','HTTP_X_FORWARDED_PROTO'=>'https'])),
    'proxy-supplied HTTPS never opens public transport');
publicWireReject(fn()=>$builder(array_replace($server,['HTTP_X_EVRASIA_V5_PILOT'=>'legacy-pilot'])),
    'pilot credentials are never a public release switch');
publicWireReject(fn()=>$builder($server),'missing real SMS/TOTP lifecycle denies public assembly');
$ports->publicFactorReady=true;
$transport=$builder($server);
publicWireCheck($transport instanceof V5HttpTransportBoundary,'synthetic gated public transport assembled');
publicWireCheck($ports->passwordCalls===0 && $ports->challengeCalls===0,
    'composition alone performs no password check or SMS');
publicWireCheck($native->perUserCalls===0,'composition is not per-user native proof');

$body=json_encode(['login'=>'synthetic-invalid','password'=>'synthetic-invalid',
    'device_id'=>'sz_'.str_repeat('e',64),'platform'=>'ios'],JSON_THROW_ON_ERROR);
$reply=$transport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/auth/signin',true,'application/json',$body,(string)strlen($body)
));
publicWireCheck($reply->status===401,'synthetic login reaches real handler and rejects fake credentials');
publicWireCheck($ports->passwordCalls===1 && $ports->challengeCalls===0,
    'bad first factor never begins challenge or sends SMS');


/**
 * Source-only EMAIL-first assembly regression. No accounts, connections,
 * Bitrix bootstrap, Redis queries, SQL, SMTP or OTP delivery are used.
 * All providers below deliberately refuse authentication.
 */
$denyHash = new class implements \Evrasia\Samzaberu\V5\V5PasswordHashComparatorPort {
    public function matches(string $storedHash,string $enteredPassword):bool{return false;}
};
$denyAdmission = new class implements \Evrasia\Samzaberu\V5\V5PasswordLoginAdmissionPort {
    public function permitsAfterProof(int $userId,string $enteredPassword):?bool{return null;}
};
$denyPhone = new class implements \Evrasia\Samzaberu\V5\V5PhoneLoginNormalizerPort {
    public function normalizeForLookup(string $input):?string{return null;}
};
$denyPolicy = new class implements \Evrasia\Samzaberu\V5\V5ExistingLoginPolicyPort {
    public function permits(int $userId,int $loginAttempts):?bool{return null;}
};
$denyNoMfa = new class implements \Evrasia\Samzaberu\V5\V5NoMfaFallbackPolicyPort {
    public function permitsPasswordPlusSms(int $userId):?bool{return null;}
};
$denyHooks = new class implements \Evrasia\Samzaberu\V5\V5NativeSecurityHookCoveragePort {
    public function fullyCovered(PDO $db,int $userId):?bool{return null;}
};
$makeProof=static fn(PDO $connection)=>new \Evrasia\Samzaberu\V5\V5BitrixLockedEmailOtpProof(
    $connection,$denyHash,$denyAdmission,$denyPhone,$denyPolicy,$denyNoMfa,$denyHooks,
    str_repeat('p',40),str_repeat('q',40)
);
$siteRedisNoConnection=new class {
    public int $calls=0;
    public function eval(string $lua,array $args,int $numKeys):mixed {
        $this->calls++;
        return false;
    }
};
$emailAssembly=static function (
    PDO $connection,\Evrasia\Samzaberu\V5\V5BitrixLockedEmailOtpProof $emailNative,
    ?V5AttestedExplicitSmsRecoveryPort $smsRecovery = null
) use ($server,$native,$dependencies,$ports,$access,$refresh,$siteRedisNoConnection) {
    return V5MySqlPublicComposition::buildEmailFirst(
        $connection,$server,$native,$dependencies,$ports,$ports,$ports,$ports,
        $ports,$ports,$ports,$access,$refresh,$emailNative,$siteRedisNoConnection,
        str_repeat('c',40),str_repeat('d',40),str_repeat('e',40),null,$smsRecovery
    );
};
$matchingProof=$makeProof($db);
$otherConnection=new PublicWireDb();
$foreignProof=$makeProof($otherConnection);
publicWireCheck($matchingProof->boundTo($db) && !$matchingProof->boundTo($otherConnection),
    'native email proof exposes strict physical PDO identity without executing SQL');
$earlier=$ports->passwordCalls;
publicWireReject(fn()=>$emailAssembly($db,$foreignProof),
    'email-first refuses native proof from a foreign Bitrix transaction');
publicWireCheck($ports->passwordCalls===$earlier && $siteRedisNoConnection->calls===0,
    'foreign PDO denial cannot access password or Redis providers');
$native->verified=false;
publicWireReject(fn()=>$emailAssembly($db,$matchingProof),
    'email-first denies without native release attestation');
$native->verified=true;
$dependencies->verified=false;
publicWireReject(fn()=>$emailAssembly($db,$matchingProof),
    'email-first denies without complete public dependency attestation');
$dependencies->verified=true;
$ports->publicFactorReady=null;
publicWireReject(fn()=>$emailAssembly($db,$matchingProof),
    'email-first denies without independently attested TOTP and SMS lifecycle');
$ports->publicFactorReady=true;
$emailTransport=$emailAssembly($db,$matchingProof);
publicWireCheck($emailTransport instanceof V5HttpTransportBoundary,
    'same-PDO email-first providers assemble the existing HTTP boundary');
publicWireCheck($siteRedisNoConnection->calls===0 && $ports->challengeCalls===0,
    'email-first composition does not send email or SMS or call Redis');
$beforeEmailHttp=$ports->passwordCalls;
$emailHttpResponse=$emailTransport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/auth/signin',true,'application/json',$body,(string)strlen($body)
));
publicWireCheck($emailHttpResponse->status===401
    && $ports->passwordCalls===$beforeEmailHttp+1,
    'email-first HTTP signin routes to existing password provider and denies unknown login');
publicWireCheck($ports->challengeCalls===0 && $siteRedisNoConnection->calls===0,
    'bad email-first signin cannot start a factor or reserve Redis email quota');

$manualRequest=json_encode([
    'challenge_id'=>str_repeat('f',64),
    'device_id'=>'sz_'.str_repeat('e',64),
    'platform'=>'ios',
],JSON_THROW_ON_ERROR);
$manualReply=$emailTransport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/auth/challenge/use-sms',true,
    'application/json',$manualRequest,(string)strlen($manualRequest)
));
publicWireCheck($manualReply->status===401,
    'email-first public assembly without installed site SMS recovery cannot activate manual fallback');
publicWireCheck($siteRedisNoConnection->calls===0 && $ports->challengeCalls===0,
    'uninstalled SMS fallback must not invoke SMTP, Redis or old native SMS begin');


// Attested site SMS recovery is optional, but a supplied provider MUST
// independently prove its installed eligibility; unknown and denial abort
// assembly without attempting an SMS, Redis, password or account lookup.
final class PublicWireSmsRecovery implements V5AttestedExplicitSmsRecoveryPort {
    public ?bool $attested=null;
    public int $proofCalls=0;
    public int $requests=0;
    public function verifiedExistingSiteRecovery(PDO $db):?bool {
        $this->proofCalls++;
        return $this->attested;
    }
    public function requestSmsInsteadOfEmail(string $emailChallengeId,
        string $deviceId,string $platform):PendingLoginChallenge {
        $this->requests++;
        throw new RuntimeException('private_native_recovery_not_installed');
    }
}
$manual=new PublicWireSmsRecovery();
publicWireReject(fn()=>$emailAssembly($db,$matchingProof,$manual),
    'unknown installed SMS recovery attestation rejects optional binding');
$manual->attested=false;
publicWireReject(fn()=>$emailAssembly($db,$matchingProof,$manual),
    'negative installed SMS recovery attestation rejects optional binding');
publicWireCheck($manual->proofCalls===2 && $manual->requests===0,
    'negative attestation can never dispatch paid SMS');
$manual->attested=true;
$enabledRecoveryTransport=$emailAssembly($db,$matchingProof,$manual);
publicWireCheck($enabledRecoveryTransport instanceof V5HttpTransportBoundary,
    'independently attested provider is the only optional recovery path');
$deniedSms=$enabledRecoveryTransport->dispatch(new V5StagedHttpRequest(
    'POST','/api/v5/auth/challenge/use-sms',true,
    'application/json',$manualRequest,(string)strlen($manualRequest)
));
publicWireCheck($deniedSms->status===503 && $manual->requests===1,
    'site recovery provider denial never returns SMS ticket or JWT');
publicWireCheck($siteRedisNoConnection->calls===0 && $ports->challengeCalls===0,
    'manual SMS attestation and denied dispatch never call Redis or legacy SMS');

printf("PASS: V5 public mobile composition (%d synthetic assertions)\n",$checks);
echo "REAL_NATIVE_PROVIDERS_VERIFIED=NO\n";
echo "PUBLIC_FACTORY_INSTALLED=NO\n";
echo "LIVE_V5_AUTH_ENABLED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\nSMS_SENT=NO\n";
