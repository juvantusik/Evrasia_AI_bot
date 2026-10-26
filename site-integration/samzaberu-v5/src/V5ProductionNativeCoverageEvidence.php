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
    private const UNATTESTED = [
        'password_change_or_reset',
        'user_block_and_activation',
        'logout_and_session_revocation',
        'otp_save_and_enrollment',
        'otp_delete_and_disable',
        'otp_automatic_reactivation',
        'otp_recovery_and_reset',
        'mandatory_mfa_policy_change',
        'phone_confirmation_and_reconfirmation',
        'phone_revocation_and_replacement',
    ];

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
            'required_paths' => count(self::UNATTESTED),
            'release_ready' => false,
        ];
    }

    /** @return list<string> Native paths without independently accepted proof. */
    public static function unverifiedPaths(): array
    {
        return self::UNATTESTED;
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
