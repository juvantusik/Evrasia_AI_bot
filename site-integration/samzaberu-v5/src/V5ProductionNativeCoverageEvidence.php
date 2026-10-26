<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5NativeMutationCoverage.php';
require_once __DIR__ . '/V5ProductionNativeEpochTriggerInventory.php';

use PDO;
use Throwable;

/**
 * An independently checkable production native baseline, not a release permit.
 * The four installed AUTH_EPOCH triggers are pinned, but the operator has NOT
 * verified all ten native security-mutation paths and same-transaction tests.
 *
 * This implements the existing required port fail-closed: missing evidence
 * must never become a successful V5 JWT or bypass a mandatory second factor.
 * Existing V4 PHP, shared Bitrix writers and public health/legal stay untouched.
 */
final class V5ProductionNativeCoverageEvidence implements V5TrustedInstalledNativeCoveragePort
{
    /** Never copy the enum into an independent list: new native paths must deny. */
    private static function allRequiredPaths(): array
    {
        return array_map(
            static fn(V5NativeMutationPath $p): string => $p->value,
            V5NativeMutationPath::cases()
        );
    }

    /** Exact source-pinned live trigger identities, not ten-path coverage. */
    public static function installedEpochTriggersMatch(): bool
    {
        try {
            if (PHP_SAPI !== 'fpm-fcgi'
                || !class_exists('Bitrix\\Main\\Application')) {
                return false;
            }
            return (new V5ProductionNativeEpochTriggerInventory())
                ->currentInstalledTriggersMatch(\Bitrix\Main\Application::getConnection());
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{epoch_trigger_identity:bool,covered_paths:int,required_paths:int,release_ready:bool} */
    public static function status(): array
    {
        return [
            'epoch_trigger_identity' => self::installedEpochTriggersMatch(),
            'covered_paths' => 0,
            'required_paths' => count(self::allRequiredPaths()),
            'release_ready' => false,
        ];
    }

    /** @return list<string> Native paths without independently accepted proof. */
    public static function unverifiedPaths(): array
    {
        return self::allRequiredPaths();
    }

    public function releaseVerifiedOnThisServer(PDO $db): ?bool
    {
        if (!$db instanceof V5BitrixPdoCompatibilityBridge
            || !$db->hasStrictV5HttpIngress()
            || !self::installedEpochTriggersMatch()) {
            return null;
        }
        // Critical native logout, phone, policy and OTP recovery paths
        // have no installed, tested, trusted per-user release attestations.
        // Four installed SQL triggers do not suffice for this contract.
        return null;
    }

    /** @return list<V5NativeMutationProof>|null */
    public function proofForUser(PDO $db, int $userId): ?array
    {
        if ($userId < 1) {
            return null;
        }
        // Real user-scoped coverage cannot be inferred from event names or
        // trigger counts. Never fabricate V5NativeMutationProof flags.
        return null;
    }
}
