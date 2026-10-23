<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlPasswordBoundTotpIssuer.php';
require_once __DIR__ . '/V5MySqlConsumedChallengeProof.php';
require_once __DIR__ . '/V5MySqlSessionActions.php';
require_once __DIR__ . '/V5MySqlTokenValidation.php';
require_once __DIR__ . '/V5MySqlSessionLogout.php';
require_once __DIR__ . '/V5MySqlCartRead.php';
require_once __DIR__ . '/V5HttpTransportBoundary.php';

use InvalidArgumentException;
use PDO;

/**
 * Explicit server-side composition for the existing V5 HTTP contract.
 * No bootstrap, SQL/DDL, credentials generation, route registration or activation.
 * Native providers are mandatory: this wires them, it does not attest them.
 * Call from a reviewed private site-factory.php AFTER operational admission.
 *
 * Only the existing TOTP begin lifecycle is installed. SMS begin/resend remain
 * denied until their real password-bound lifecycle/provider is implemented.
 * Existing SMS consumption still requires sent receipt/current native proofs.
 */
final class V5MySqlPilotComposition
{
    /** @param list<int> $allowedUserIds Explicit operator-approved pilot users. */
    public static function build(
        PDO $db,
        array $allowedUserIds,
        V5PasswordProofPort $passwords,
        V5TrustProofPort $trust,
        V5LockedStrongSessionProofPort $lockedTrust,
        V5LockedPasswordTotpChallengeProofPort $totpBegin,
        V5LockedChallengeNativeFactorPort $factors,
        V5NativeSecurityHookCoveragePort $coverage,
        V5LockedBonusPinReaderPort $pinReader,
        V5AccessJwtCodec $access,
        V5OpaqueRefreshTokens $refresh,
        #[\SensitiveParameter] string $bindingKey,
        #[\SensitiveParameter] string $smsCodeHmacKey,
        ?V5LockedCartReaderPort $cartReader = null,
    ): V5HttpTransportBoundary {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $db->inTransaction()) {
            throw new InvalidArgumentException('V5 composition connection unavailable');
        }
        $scope = new V5PilotScopedNativePorts($db, $allowedUserIds, $passwords,
            $trust, $lockedTrust, $totpBegin, $factors, $coverage, $pinReader);
        // One binding authority, factor adapter, session store and connection.
        // The consumed proof must NEVER be replaced by the trust provider's
        // consumed-grant callback, even if that callback returns true.
        $grantProof = new V5MySqlConsumedChallengeProof($scope, $scope, $bindingKey);
        $sessions = new V5MySqlStrongSessionStore(
            $db, $access, $refresh, $grantProof, $scope, $bindingKey
        );
        $challenges = new V5MySqlChallengeHttpPort(
            new V5MySqlChallengeConsumption($db, $scope, $bindingKey, $smsCodeHmacKey),
            new V5MySqlPasswordBoundTotpIssuer($db, $scope, $bindingKey)
        );
        return new V5HttpTransportBoundary(new V5AuthHttpHandler(
            new V5LoginWorkflow($scope, $scope, $challenges, $sessions),
            new V5MySqlSessionActions($sessions, $scope),
            new V5MySqlTokenValidation($sessions),
            null,
            new V5MySqlSessionLogout($sessions),
            $cartReader === null ? null : new V5MySqlCartRead($sessions, $cartReader)
        ));
    }
}

/**
 * @internal Admission AND native proof, never admission INSTEAD OF proof.
 * Immutable per-request scope; not populated from request JSON or JWT claims.
 * Refresh and PIN reach this scope via the existing locked coverage check,
 * including sessions issued before the operator changes the pilot user list.
 * The first-factor provider must retain its own attempt/rate/account controls;
 * its identity lookup necessarily precedes checking the resolved account ID.
 */
final readonly class V5PilotScopedNativePorts implements V5PasswordProofPort,
    V5TrustProofPort, V5LockedStrongSessionProofPort,
    V5LockedPasswordTotpChallengeProofPort, V5LockedChallengeNativeFactorPort,
    V5NativeSecurityHookCoveragePort, V5LockedBonusPinReaderPort
{
    /** @param list<int> $allowedUserIds */
    public function __construct(
        private PDO $db,
        private array $allowedUserIds,
        private V5PasswordProofPort $passwords,
        private V5TrustProofPort $trust,
        private V5LockedStrongSessionProofPort $lockedTrust,
        private V5LockedPasswordTotpChallengeProofPort $totpBegin,
        private V5LockedChallengeNativeFactorPort $factors,
        private V5NativeSecurityHookCoveragePort $coverage,
        private V5LockedBonusPinReaderPort $pinReader,
    ) {
        if (!array_is_list($allowedUserIds) || count($allowedUserIds) < 1
            || count($allowedUserIds) > 32) {
            throw new InvalidArgumentException('V5 pilot account scope unavailable');
        }
        $seen = [];
        foreach ($allowedUserIds as $id) {
            if (!is_int($id) || $id < 1 || isset($seen[$id])) {
                throw new InvalidArgumentException('V5 pilot account scope unavailable');
            }
            $seen[$id] = true;
        }
    }

    public function verifyWithoutLogin(
        #[\SensitiveParameter] string $login, #[\SensitiveParameter] string $password
    ): ?PasswordVerifiedAccount {
        $account = $this->passwords->verifyWithoutLogin($login, $password);
        return $account !== null && $account->accountAllowed
            && $this->allowed($account->userId) ? $account : null;
    }

    public function evaluate(int $userId, string $deviceId, string $platform,
        #[\SensitiveParameter] string $trustToken): MobileTrustAssessment
    {
        return $this->allowed($userId)
            ? $this->trust->evaluate($userId, $deviceId, $platform, $trustToken)
            : new MobileTrustAssessment(false, false);
    }

    public function verifiedTrustWithUserLock(PDO $db, PasswordVerifiedAccount $account,
        string $deviceId, string $platform, #[\SensitiveParameter] string $trustToken): bool
    {
        return $this->locked($db, $account->userId)
            && $this->lockedTrust->verifiedTrustWithUserLock(
                $db, $account, $deviceId, $platform, $trustToken
            ) === true;
    }

    public function verifiedConsumedGrantWithUserLock(PDO $db, ConsumedLoginChallenge $grant): bool
    {
        // Never delegate this to an arbitrary trust adapter. The composition
        // always installs the durable V5MySqlConsumedChallengeProof outside us.
        return false;
    }

    public function reverifyCurrentPasswordAndTotpWithUserLock(PDO $db,
        PasswordVerifiedAccount $account, #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password, int $lockedAuthEpoch): ?string
    {
        return $this->locked($db, $account->userId)
            ? $this->totpBegin->reverifyCurrentPasswordAndTotpWithUserLock(
                $db, $account, $login, $password, $lockedAuthEpoch
            ) : null;
    }

    public function currentFactorEligibleWithLocks(PDO $db, int $userId,
        ChallengeKind $kind, string $challengeAuthEpoch, ?string $verifiedPhoneStamp): bool
    {
        return $this->locked($db, $userId)
            && $this->factors->currentFactorEligibleWithLocks(
                $db, $userId, $kind, $challengeAuthEpoch, $verifiedPhoneStamp
            ) === true;
    }

    public function consumeNativeTotpWithLocks(PDO $db, int $userId,
        #[\SensitiveParameter] string $code): bool
    {
        return $this->locked($db, $userId)
            && $this->factors->consumeNativeTotpWithLocks($db, $userId, $code) === true;
    }

    public function fullyCovered(PDO $db, int $userId): ?bool
    {
        return $this->locked($db, $userId)
            && $this->coverage->fullyCovered($db, $userId) === true;
    }

    public function readLockedBonusPin(PDO $db, int $userId): ?string
    {
        return $this->locked($db, $userId) ? $this->pinReader->readLockedBonusPin($db, $userId) : null;
    }

    private function allowed(int $id): bool
    {
        return in_array($id, $this->allowedUserIds, true);
    }

    private function locked(PDO $db, int $id): bool
    {
        // Individual stores/providers retain their actual row-lock proofs.
        // inTransaction alone is not evidence that native rows are locked.
        return $db === $this->db && $db->inTransaction() && $this->allowed($id);
    }
}
