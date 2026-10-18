<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5StagedSignupCoordinator.php';
require_once __DIR__ . '/V5StagedCUserSourceGate.php';
require_once __DIR__ . '/V5BitrixStagedSignupConsentWriter.php';

use RuntimeException;

/**
 * Non-deployable native signup integration scaffold. No positive Bitrix
 * transaction/side-effect attestation exists: approval is HARD DENIED.
 *
 * We deliberately do not call CUser::Add on any host. A future revision
 * must replace this denial only after independent isolated tests have
 * demonstrated atomic rollback including ratings/events/cache/files.
 */
final class V5StagedBitrixSignupAdapter implements V5StagedSignupNativePort
{
    public function __construct(
        private string $stagingRoot,
        private V5BitrixStagedSignupConsentWriter $consents,
        private V5SignupNativeConsentPlan $plan,
    ) {}

    public function safeToCreateWithoutExternalEffects(): bool
    {
        try {
            V5StagedCUserSourceGate::assertReviewed($this->stagingRoot);
        } catch (\Throwable) {
            return false;
        }
        // Source identity is not evidence that CUser::Add handlers,
        // especially CRatings / CUser::AppendUserGroup, are reversible.
        return false;
    }

    public function createUserInsideOwnedTransaction(): int
    {
        throw new RuntimeException('v5_signup_cuser_side_effects_not_attested');
    }

    public function persistNativeConsentsFor(int $newUserId): array
    {
        // Never invoke the consent writer without the original native user
        // being created and independently proven on the owned connection.
        throw new RuntimeException('v5_signup_cuser_side_effects_not_attested');
    }

    public function verifyUncommitted(int $newUserId, array $consentIds): bool
    {
        return false;
    }

    public function transactionContinuityBeforeCommit(): bool
    {
        return false;
    }

    public function verifyCommitted(int $newUserId, array $consentIds): bool
    {
        return false;
    }
}
