<?php
declare(strict_types=1);

// Reuse the existing fail-closed synthetic Bitrix fixture and its own 13 checks.
// This is not a native positive E2E, nor proof of CUser::Add transaction safety.
require_once __DIR__ . '/V5BitrixStagedSignupConsentWriterTest.php';
require_once __DIR__ . '/../src/V5StagedSignupCoordinator.php';

final class DeniedStagedSignupNative implements \Evrasia\Samzaberu\V5\V5StagedSignupNativePort
{
    public int $creates = 0;
    public function safeToCreateWithoutExternalEffects(): bool { return false; }
    public function createUserInsideOwnedTransaction(): int {
        $this->creates++;
        throw new \RuntimeException('must never execute');
    }
    public function persistNativeConsentsFor(int $newUserId): array { throw new \RuntimeException('must never execute'); }
    public function verifyUncommitted(int $newUserId, array $consentIds): bool { return false; }
    public function verifyCommitted(int $newUserId, array $consentIds): bool { return false; }
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
putenv('V5_STAGE_WRITE_ACK');
echo "PASS: V5 staged signup coordinator denies unreviewed native effects (2 assertions)\n";
echo "REAL_BITRIX_NATIVE_CALLS=NO\n";
echo "PRODUCTION_DEPLOYED=NO\n";
