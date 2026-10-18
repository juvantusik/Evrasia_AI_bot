<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SignupNativeConsentPlan.php';
require_once __DIR__ . '/V5BitrixPdoCompatibilityBridge.php';

use RuntimeException;
use Throwable;

/**
 * Feature-only staging orchestration contract. Not an HTTP route, Bitrix
 * adapter, or permission to write on production.
 *
 * Native CUser::Add and its OnAfterUserAdd callbacks are not yet proven
 * transaction-safe. Until a separately reviewed, isolated native adapter
 * attests all effects, this coordinator has no deployable implementation.
 */
interface V5StagedSignupNativePort
{
    /** Must reject production and any unreviewed native non-SQL side effect. */
    public function safeToCreateWithoutExternalEffects(): bool;
    /** Called strictly AFTER beginTransaction, on the owner's native connection. */
    public function createUserInsideOwnedTransaction(): int;
    /** Fresh locked legal catalog and native ID resolution on that connection. */
    public function persistNativeConsentsFor(int $newUserId): array;
    /** Assert same native connection, transaction depth and savepoint before COMMIT. */
    public function transactionContinuityBeforeCommit(): bool;
    /** Independently verify the new user and every returned consent event. */
    public function verifyUncommitted(int $newUserId, array $consentIds): bool;
    /** Post-commit readback on a fresh authoritative native connection. */
    public function verifyCommitted(int $newUserId, array $consentIds): bool;
}

/**
 * No automatic retry: a lost/uncertain commit may have created the user.
 * Errors are deliberately generic; do not leak registration identifiers.
 */
final class V5StagedSignupCoordinator
{
    private bool $used = false;

    public function __construct(
        private V5BitrixPdoCompatibilityBridge $transaction,
        private V5StagedSignupNativePort $native,
    ) {}

    public function register(): void
    {
        if ($this->used) {
            throw new RuntimeException('v5_signup_coordinator_already_used');
        }
        $this->used = true;
        $host = gethostname();
        $first = is_string($host) ? strtolower(explode('.', $host)[0]) : '';
        if (PHP_SAPI !== 'cli'
            || $first === ''
            || in_array($first, ['evrasia', 'eur-bot-01', 'prod', 'production'], true)
            || getenv('V5_STAGE_WRITE_ACK') !== 'ISOLATED_STAGING_ONLY'
            || !$this->native->safeToCreateWithoutExternalEffects()
            || $this->transaction->inTransaction()) {
            throw new RuntimeException('v5_signup_native_effects_not_attested');
        }

        // A refused or uncertain BEGIN is never ours to roll back.
        $this->transaction->beginTransaction();
        try {
            $id = $this->native->createUserInsideOwnedTransaction();
            if (V5SignupNativeConsentPlan::positiveInt($id) === null
                || !$this->transaction->inTransaction()) {
                throw new RuntimeException('v5_signup_native_user_unverified');
            }
            $events = $this->native->persistNativeConsentsFor($id);
            if (!array_is_list($events) || count($events) < 2
                || count($events) > 3
                || count(array_unique($events)) !== count($events)
                || count(array_filter($events, static fn($v) =>
                    V5SignupNativeConsentPlan::positiveInt($v) !== null)) !== count($events)
                || !$this->transaction->inTransaction()
                || !$this->native->verifyUncommitted($id, $events)) {
                throw new RuntimeException('v5_signup_native_consents_unverified');
            }
        } catch (Throwable $cause) {
            // A hidden COMMIT+BEGIN or lost native connection makes the entire
            // user/consent outcome unknowable. A rollback on that connection
            // cannot prove reversal: never retry or claim atomicity.
            if ($cause instanceof RuntimeException && in_array($cause->getMessage(), [
                'v5_signup_consent_transaction_uncertain',
                'v5_signup_native_transaction_lost',
                'v5_signup_native_connection_changed',
                'v5_bitrix_commit_uncertain',
                'v5_bitrix_rollback_uncertain',
            ], true)) {
                throw new RuntimeException('v5_signup_transaction_uncertain_no_retry', 0, $cause);
            }
            try {
                $this->transaction->rollBack();
            } catch (Throwable) {
                throw new RuntimeException('v5_signup_rollback_uncertain');
            }
            throw new RuntimeException('v5_signup_rolled_back', 0, $cause);
        }

        // A bridge ownership boolean is not enough: the native port must
        // demonstrate the original native transaction is still intact.
        try {
            if (!$this->native->transactionContinuityBeforeCommit()) {
                throw new RuntimeException('v5_signup_transaction_uncertain_no_retry');
            }
        } catch (Throwable $cause) {
            throw new RuntimeException('v5_signup_transaction_uncertain_no_retry', 0, $cause);
        }

        // All native writes must still be uncommitted here. Commit exceptions
        // have an UNKNOWN outcome; deliberately do not roll back or retry.
        try {
            $this->transaction->commit();
        } catch (Throwable) {
            throw new RuntimeException('v5_signup_commit_uncertain');
        }

        // A failed post-commit check is an incident, never a reason to retry.
        try {
            $verified = $this->native->verifyCommitted($id, $events);
        } catch (Throwable $cause) {
            throw new RuntimeException('v5_signup_postcommit_unverified_no_retry', 0, $cause);
        }
        if (!$verified) {
            throw new RuntimeException('v5_signup_postcommit_unverified_no_retry');
        }
    }
}
