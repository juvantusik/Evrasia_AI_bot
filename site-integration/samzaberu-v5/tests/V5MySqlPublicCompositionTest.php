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
    V5StagedHttpRequest,
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

printf("PASS: V5 public mobile composition (%d synthetic assertions)\n",$checks);
echo "REAL_NATIVE_PROVIDERS_VERIFIED=NO\n";
echo "PUBLIC_FACTORY_INSTALLED=NO\n";
echo "LIVE_V5_AUTH_ENABLED=NO\n";
echo "PRODUCTION_DATABASE_CONNECTION=NO\nDATABASE_WRITE=NO\nSMS_SENT=NO\n";
