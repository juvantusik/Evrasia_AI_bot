<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlChallengeConsumption.php';
require_once __DIR__ . '/V5LoginWorkflow.php';

/**
 * A server-only, site-specific lifecycle provider for starting *password-
 * proven* challenges and safely reserving/resending SMS.
 *
 * Until a concrete provider is attested on isolated Bitrix staging, there is
 * deliberately NO default to open a new SMS/TOTP challenge or send a message.
 */
interface V5ChallengeLifecyclePort
{
    public function begin(
        int $userId,string $deviceId,string $platform,ChallengeKind $kind
    ):PendingLoginChallenge;

    public function resendSms(
        string $challengeId,string $deviceId,string $platform
    ):PendingLoginChallenge;
}

/**
 * A lifecycle which supplies strong first-factor evidence, as opposed to
 * the legacy userId-only method.
 */
interface V5PasswordBoundChallengeLifecyclePort
    extends V5ChallengeLifecyclePort, V5PasswordBoundChallengePort
{}

/**
 * Partial, fail-closed V5AtomicChallengePort: full real PDO SMS/TOTP code
 * consumption is implemented, but initial password-bound issuance and SMS
 * resend require an explicit verified site-native lifecycle dependency.
 *
 * Keeping them separated avoids advertising "challenge_sent" for a message
 * that has only been queued in an outbox and not actually delivered.
 */
/**
 * Optional email verifier: public code consumption must first check whether
 * a random challenge ID belongs to the independent V5 email ledger.
 * A present email challenge MUST NEVER fall through to SMS/TOTP verification.
 */
interface V5EmailChallengeVerificationPort
{
    public function ownsEmailChallenge(string $challengeId): bool;
    public function verifyAndConsume(
        string $challengeId,
        #[\SensitiveParameter] string $code,
        string $deviceId,
        string $platform
    ): ?ConsumedLoginChallenge;
}

final readonly class V5MySqlChallengeHttpPort
    implements V5AtomicChallengePort, V5PasswordBoundChallengePort, V5ExplicitSmsRecoveryPort
{
    public function __construct(
        private V5MySqlChallengeConsumption $consumption,
        private ?V5ChallengeLifecyclePort $lifecycle = null,
        private ?V5EmailChallengeVerificationPort $email = null,
        private ?V5ExplicitSmsRecoveryPort $manualSmsRecovery = null,
    ) {}

    public function begin(
        int $userId,string $deviceId,string $platform,ChallengeKind $kind
    ):PendingLoginChallenge {
        // NO unbound user ID can ever create a password-proven row.
        throw new \RuntimeException('challenge_unavailable');
    }

    public function beginBound(
        PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        string $deviceId,
        string $platform,
        ChallengeKind $kind,
    ):PendingLoginChallenge {
        if (!$this->lifecycle instanceof V5PasswordBoundChallengeLifecyclePort) {
            throw new \RuntimeException('challenge_unavailable');
        }
        return $this->lifecycle->beginBound(
            $account,$login,$password,$deviceId,$platform,$kind
        );
    }

    public function verifyAndConsume(
        string $challengeId,
        #[\SensitiveParameter] string $code,
        string $deviceId,string $platform
    ):?ConsumedLoginChallenge {
        if ($this->email !== null && $this->email->ownsEmailChallenge($challengeId)) {
            return $this->email->verifyAndConsume($challengeId,$code,$deviceId,$platform);
        }
        return $this->consumption->verifyAndConsume(
            $challengeId,$code,$deviceId,$platform
        );
    }

    /**
     * Only a separately installed native recovery provider can send SMS.
     * Merely knowing a challenge ID never grants phone access or a code.
     */
    public function requestSmsInsteadOfEmail(
        string $emailChallengeId,string $deviceId,string $platform
    ):PendingLoginChallenge {
        if ($this->manualSmsRecovery === null) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        return $this->manualSmsRecovery->requestSmsInsteadOfEmail(
            $emailChallengeId,$deviceId,$platform
        );
    }

    public function resendSms(
        string $challengeId,string $deviceId,string $platform
    ):PendingLoginChallenge {
        if($this->lifecycle===null)
            throw new \RuntimeException('challenge_unavailable');
        return $this->lifecycle->resendSms(
            $challengeId,$deviceId,$platform
        );
    }
}
