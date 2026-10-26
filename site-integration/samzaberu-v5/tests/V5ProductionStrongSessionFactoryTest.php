<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5ProductionStrongSessionFactory.php';

use Evrasia\Samzaberu\V5\{
    V5ProductionStrongSessionFactory,V5TrustedInstalledNativeCoveragePort,
    V5LockedStrongSessionProofPort,V5NativeMutationProof,
    V5AccessJwtCodec,V5OpaqueRefreshTokens,
    PasswordVerifiedAccount,ConsumedLoginChallenge
};

final class NativeEvidenceMustNotRun implements V5TrustedInstalledNativeCoveragePort
{
    public int $calls=0;
    public function releaseVerifiedOnThisServer(PDO $db):?bool {
        $this->calls++;
        throw new RuntimeException('fake evidence must not be used in CLI');
    }
    public function proofForUser(PDO $db,int $userId):?array {
        $this->calls++;
        throw new RuntimeException('fake user proof must not be used in CLI');
    }
}
final class NativeStrongProofMustNotRun implements V5LockedStrongSessionProofPort
{
    public int $calls=0;
    public function verifiedTrustWithUserLock(
        PDO $db, PasswordVerifiedAccount $account,string $deviceId,
        string $platform,string $trustToken
    ):bool { $this->calls++;return false; }
    public function verifiedConsumedGrantWithUserLock(
        PDO $db,ConsumedLoginChallenge $grant
    ):bool { $this->calls++;return false; }
}

$checks=0;
$yes=static function(bool $ok,string $name) use (&$checks):void {
    if (!$ok) throw new RuntimeException('FAIL: '.$name);
    $checks++;
};
$evidence=new NativeEvidenceMustNotRun();
$proof=new NativeStrongProofMustNotRun();
$access=new V5AccessJwtCodec(['native_kid'=>str_repeat('k',64)],'native_kid');
$refresh=new V5OpaqueRefreshTokens(str_repeat('r',64));

$yes(PHP_SAPI==='cli','only unprivileged CLI mock test');
try {
    V5ProductionStrongSessionFactory::buildForProtectedHttp(
        $evidence,$proof,$access,$refresh,str_repeat('b',64)
    );
    throw new RuntimeException('FAIL: unauthorized native factory built');
} catch (InvalidArgumentException $e) {
    $yes($e->getMessage()==='v5_native_strong_factory_unavailable',
        'CLI fail closed before native factory or callbacks');
}
$yes($evidence->calls===0,'no native release evidence invoked in CLI');
$yes($proof->calls===0,'no password/trust/MFA proof invoked in CLI');
$yes(!class_exists('Bitrix\Main\Application',false),
    'no production Bitrix bootstrap');
try {
    V5ProductionStrongSessionFactory::buildForProtectedHttp(
        $evidence,$proof,$access,$refresh,'weak'
    );
    throw new RuntimeException('FAIL: weak binding key accepted');
} catch (InvalidArgumentException $e) {
    $yes($e->getMessage()==='v5_native_strong_factory_unavailable',
        'weak server binding key rejected');
}
echo "PASS: V5 native strong-session factory release gate ($checks assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_WRITE=NO\nJWT_ISSUED=NO\nPUBLIC_V5_ENABLED=NO\n";
