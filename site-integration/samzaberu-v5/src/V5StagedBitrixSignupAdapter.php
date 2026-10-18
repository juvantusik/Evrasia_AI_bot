<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5StagedSignupCoordinator.php';
require_once __DIR__ . '/V5StagedCUserSourceGate.php';
require_once __DIR__ . '/V5BitrixStagedSignupConsentWriter.php';
require_once __DIR__ . '/V5NativeCUserAddCandidate.php';

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
        private ?V5NativeCUserAddCandidate $userCreator = null,
        private array $serverFields = [],
    ) {}

    private bool $userCreated = false;
    private ?int $createdUserId = null;
    private bool $consentAttempted = false;

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
        // The coordinator will refuse this call while native side effects
        // remain unattested. Direct callers must also be denied.
        if (!$this->safeToCreateWithoutExternalEffects()
            || $this->userCreator === null || $this->userCreated) {
            throw new RuntimeException('v5_signup_cuser_side_effects_not_attested');
        }
        $this->userCreated = true;
        $this->createdUserId = $this->userCreator->add($this->serverFields);
        return $this->createdUserId;
    }

    public function persistNativeConsentsFor(int $newUserId): array
    {
        // This writer is bound to the exact source-attested native connection;
        // never accept an arbitrary incoming USER_ID.
        if (!$this->safeToCreateWithoutExternalEffects()
            || !$this->userCreated || $this->consentAttempted
            || $this->createdUserId !== $newUserId) {
            throw new RuntimeException('v5_signup_cuser_side_effects_not_attested');
        }
        $this->consentAttempted = true;
        return $this->consents->persistForNewUser($newUserId, $this->plan);
    }

    public function verifyUncommitted(int $newUserId, array $consentIds): bool
    {
        return false;
    }

    public function transactionContinuityBeforeRollback(): bool
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
