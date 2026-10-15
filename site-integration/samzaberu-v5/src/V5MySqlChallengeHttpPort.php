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
 * Partial, fail-closed V5AtomicChallengePort: full real PDO SMS/TOTP code
 * consumption is implemented, but initial password-bound issuance and SMS
 * resend require an explicit verified site-native lifecycle dependency.
 *
 * Keeping them separated avoids advertising "challenge_sent" for a message
 * that has only been queued in an outbox and not actually delivered.
 */
final readonly class V5MySqlChallengeHttpPort implements V5AtomicChallengePort
{
    public function __construct(
        private V5MySqlChallengeConsumption $consumption,
        private ?V5ChallengeLifecyclePort $lifecycle = null,
    ) {}

    public function begin(
        int $userId,string $deviceId,string $platform,ChallengeKind $kind
    ):PendingLoginChallenge {
        if($this->lifecycle===null)
            throw new \RuntimeException('challenge_unavailable');
        return $this->lifecycle->begin($userId,$deviceId,$platform,$kind);
    }

    public function verifyAndConsume(
        string $challengeId,
        #[\SensitiveParameter] string $code,
        string $deviceId,string $platform
    ):?ConsumedLoginChallenge {
        return $this->consumption->verifyAndConsume(
            $challengeId,$code,$deviceId,$platform
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
