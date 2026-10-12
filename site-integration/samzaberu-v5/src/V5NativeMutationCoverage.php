<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SecurityEventVersions.php';

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Explicit native mutation paths: generic after-user hooks alone do not
 * cover direct Otp::save/delete or OtpEvents::onRecheckDeactivate.
 * This is a RELEASE GATE, not an installer of Bitrix event handlers.
 */
enum V5NativeMutationPath: string
{
    case PasswordChangeOrReset = 'password_change_or_reset';
    case UserBlockAndActivation = 'user_block_and_activation';
    case LogoutAndSessionRevocation = 'logout_and_session_revocation';
    case OtpSaveAndEnrollment = 'otp_save_and_enrollment';
    case OtpDeleteAndDisable = 'otp_delete_and_disable';
    case OtpAutomaticReactivation = 'otp_automatic_reactivation';
    case OtpRecoveryAndReset = 'otp_recovery_and_reset';
    case MandatoryMfaPolicyChange = 'mandatory_mfa_policy_change';
    case PhoneConfirmationAndReconfirmation = 'phone_confirmation_and_reconfirmation';
    case PhoneRevocationAndReplacement = 'phone_revocation_and_replacement';
}

/**
 * Evidence for ONE installed, reviewed native mutation path.
 * Each flag needs a concrete staging test on the target Bitrix release.
 * Source hash is the deployed native writer source (not a password hash).
 */
final readonly class V5NativeMutationProof
{
    public function __construct(
        public V5NativeMutationPath $path,
        public string $nativeSourceSha256,
        public bool $usesSamePdoConnection,
        public bool $locksBUserBeforeMutation,
        public bool $updatesEpochInSameTransaction,
        public bool $rollsBackNativeAndEpochTogether,
        public bool $concurrentRaceTestAccepted,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $nativeSourceSha256) !== 1) {
            throw new InvalidArgumentException('Invalid native source fingerprint');
        }
    }

    public function complete(): bool
    {
        return $this->usesSamePdoConnection
            && $this->locksBUserBeforeMutation
            && $this->updatesEpochInSameTransaction
            && $this->rollsBackNativeAndEpochTogether
            && $this->concurrentRaceTestAccepted;
    }
}

/** All ten paths are mandatory. Unknown, missing or duplicate paths deny. */
final readonly class V5NativeMutationCoverageMatrix
{
    /** @var array<string,V5NativeMutationProof> */
    private array $byPath;

    /** @param list<V5NativeMutationProof> $proofs */
    public function __construct(array $proofs)
    {
        $map = [];
        foreach ($proofs as $proof) {
            if (!$proof instanceof V5NativeMutationProof) {
                throw new InvalidArgumentException('Unrecognized native mutation proof');
            }
            if (isset($map[$proof->path->value])) {
                throw new InvalidArgumentException('Duplicate native mutation proof');
            }
            $map[$proof->path->value] = $proof;
        }
        $this->byPath = $map;
    }

    /** @return list<string> Only non-PII event category identifiers. */
    public function uncoveredPaths(): array
    {
        $uncovered = [];
        foreach (V5NativeMutationPath::cases() as $path) {
            if (!isset($this->byPath[$path->value])
                || !$this->byPath[$path->value]->complete()) {
                $uncovered[] = $path->value;
            }
        }
        return $uncovered;
    }

    public function fullyCovered(): bool
    {
        return $this->uncoveredPaths() === [];
    }
}

/**
 * TRUST BOUNDARY. A real implementation must attest current deployed
 * code and durable per-user coverage from independently verified staging
 * release evidence. A boolean config, HTTP argument, or a list of Bitrix
 * OnAfter* handlers is NOT sufficient. No production provider exists.
 */
interface V5TrustedInstalledNativeCoveragePort
{
    public function releaseVerifiedOnThisServer(PDO $db): ?bool;

    /** @return list<V5NativeMutationProof>|null */
    public function proofForUser(PDO $db, int $userId): ?array;
}

/**
 * A strict adapter for the existing V5NativeSecurityHookCoveragePort.
 * DO NOT wire a staging fake into live code. Unknowns never return true.
 * No queries are made by the gate itself; the trusted evidence port is
 * responsible for its own connection/identity checks and data provenance.
 */
final readonly class V5StrictNativeSecurityHookCoverage implements V5NativeSecurityHookCoveragePort
{
    public function __construct(private V5TrustedInstalledNativeCoveragePort $evidence) {}

    public function fullyCovered(PDO $db, int $userId): ?bool
    {
        if ($userId < 1) {
            return null;
        }
        try {
            if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
                || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
                || $this->evidence->releaseVerifiedOnThisServer($db) !== true
            ) {
                return null;
            }
            $proofs = $this->evidence->proofForUser($db, $userId);
            if (!is_array($proofs)) {
                return null;
            }
            return (new V5NativeMutationCoverageMatrix($proofs))->fullyCovered() ? true : null;
        } catch (Throwable) {
            // Never expose source, account, credentials or SQL diagnostics.
            return null;
        }
    }
}
