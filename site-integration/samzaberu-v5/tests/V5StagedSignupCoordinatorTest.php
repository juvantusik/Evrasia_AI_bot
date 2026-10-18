<?php
declare(strict_types=1);

// Reuse the existing fail-closed synthetic Bitrix fixture and its own 13 checks.
// This is not a native positive E2E, nor proof of CUser::Add transaction safety.
require_once __DIR__ . '/V5BitrixStagedSignupConsentWriterTest.php';
require_once __DIR__ . '/../src/V5StagedSignupCoordinator.php';

final class DeniedStagedSignupNative implements \Evrasia\Samzaberu\V5\V5StagedSignupNativePort
{
    public int $creates = 0;
    public bool $approved = false;
    public string $failure = '';
    public function safeToCreateWithoutExternalEffects(): bool { return $this->approved; }
    public function createUserInsideOwnedTransaction(): int {
        $this->creates++;
        if ($this->failure !== '') throw new \RuntimeException($this->failure);
        return 5678;
    }
    public function persistNativeConsentsFor(int $newUserId): array { return [11, 12]; }
    public function verifyUncommitted(int $newUserId, array $consentIds): bool { return true; }
    public function verifyCommitted(int $newUserId, array $consentIds): bool { return true; }
}

use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqliConnection;
use Evrasia\Samzaberu\V5\V5BitrixPdoCompatibilityBridge;
use Evrasia\Samzaberu\V5\V5StagedSignupCoordinator;

$fake = new MysqliConnection();
Application::$connection = $fake;
$bridge = new V5BitrixPdoCompatibilityBridge($fake, new SyntheticNativeSignupPermit());
$port = new DeniedStagedSignupNative();
$coordinator = new V5StagedSignupCoordinator($bridge, $port);
putenv('V5_STAGE_WRITE_ACK');
$denied = 0;
foreach ([1, 2] as $attempt) {
    try {
        $coordinator->register();
    } catch (\RuntimeException $e) {
        $denied++;
    }
}
if ($denied !== 2 || $port->creates !== 0 || $fake->beginCalls !== 0
    || $fake->commitCalls !== 0 || $fake->rollbackCalls !== 0
    || $fake->writes !== 0) {
    throw new \RuntimeException('FAIL: V5 staged signup default-deny guard');
}
// Negative synthetic transaction paths, not native Bitrix E2E.
putenv('V5_STAGE_WRITE_ACK=ISOLATED_STAGING_ONLY');
$fake2 = new MysqliConnection();
Application::$connection = $fake2;
$bridge2 = new V5BitrixPdoCompatibilityBridge($fake2, new SyntheticNativeSignupPermit());
$port2 = new DeniedStagedSignupNative();
$port2->approved = true;
$port2->failure = 'synthetic_regular_error';
try {
    (new V5StagedSignupCoordinator($bridge2, $port2))->register();
    throw new \RuntimeException('FAIL: regular failure permitted');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'v5_signup_rolled_back') throw $e;
}
if ($fake2->rollbackCalls !== 1 || $fake2->commitCalls !== 0) {
    throw new \RuntimeException('FAIL: regular failure did not roll back');
}

$fake3 = new MysqliConnection();
Application::$connection = $fake3;
$bridge3 = new V5BitrixPdoCompatibilityBridge($fake3, new SyntheticNativeSignupPermit());
$port3 = new DeniedStagedSignupNative();
$port3->approved = true;
$port3->failure = 'v5_signup_consent_transaction_uncertain';
try {
    (new V5StagedSignupCoordinator($bridge3, $port3))->register();
    throw new \RuntimeException('FAIL: uncertain failure permitted');
} catch (\RuntimeException $e) {
    if ($e->getMessage() !== 'v5_signup_transaction_uncertain_no_retry') throw $e;
}
if ($fake3->rollbackCalls !== 0 || $fake3->commitCalls !== 0) {
    throw new \RuntimeException('FAIL: uncertain state incorrectly rolled back');
}
putenv('V5_STAGE_WRITE_ACK');
echo "PASS: V5 coordinator default deny, ordinary rollback, uncertain no-retry (4 assertions)\n";
echo "REAL_BITRIX_NATIVE_CALLS=NO\n";
echo "PRODUCTION_DEPLOYED=NO\n";
