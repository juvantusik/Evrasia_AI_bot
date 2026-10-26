<?php
declare(strict_types=1);

/**
 * Negative-only production coverage test. The fixture deliberately has no
 * Bitrix connection, user account, database or installed release evidence.
 * It must never yield an authorization-capable proof or native release.
 */
require_once __DIR__ . '/../src/V5ProductionNativeCoverageEvidence.php';

use Evrasia\Samzaberu\V5\V5NativeMutationPath;
use Evrasia\Samzaberu\V5\V5ProductionNativeCoverageEvidence;
use Evrasia\Samzaberu\V5\V5TrustedInstalledNativeCoveragePort;

final class V5NoConnectPdo extends PDO
{
    public function __construct() {}
}

$checks = 0;
$ok = static function (bool $result, string $label) use (&$checks): void {
    if (!$result) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
};

$provider = new V5ProductionNativeCoverageEvidence();
$ok($provider instanceof V5TrustedInstalledNativeCoveragePort,
    'real existing coverage interface implemented');
$ok($provider::unverifiedPaths() === array_map(
    static fn(V5NativeMutationPath $p): string => $p->value,
    V5NativeMutationPath::cases()
), 'unverified paths derived from the canonical native enum');
$ok(count($provider::unverifiedPaths()) === 10, 'all ten paths currently unverified');

$status = $provider::status();
$ok(($status['epoch_trigger_identity'] ?? null) === false,
    'offline CLI must not claim live trigger ownership');
$ok(($status['release_ready'] ?? null) === false,
    'offline status never marks auth release ready');
$ok(($status['covered_paths'] ?? null) === 0,
    'missing live atomic security-event evidence remains zero');
$ok(($status['required_paths'] ?? null) === count(V5NativeMutationPath::cases()),
    'status required count is canonical enum');

$db = new V5NoConnectPdo();
$ok($provider->releaseVerifiedOnThisServer($db) === null,
    'unverified PDO cannot authorize release');
$ok($provider->proofForUser($db, 1) === null,
    'valid positive user id is not per-user attestation');
$ok($provider->proofForUser($db, -1) === null,
    'invalid user id denied');

$ok(!class_exists('Bitrix\\Main\\Application', false),
    'test cannot silently bootstrap production Bitrix');
echo "PASS: V5 native production coverage baseline ($checks assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_CONNECTION=NO\n";
echo "DATABASE_WRITE=NO\nJWT_ISSUED=NO\nPUBLIC_AUTH_ENABLED=NO\n";
