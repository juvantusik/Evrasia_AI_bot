<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SeparatedFactorLifecycle.php';

/**
 * V5-only routing shim around the ALREADY attested native TOTP/SMS lifecycle.
 * Two typed views let V5SeparatedFactorLifecycle keep its existing strict
 * trust -> TOTP -> email policy without implementing another OTP algorithm.
 *
 * This class is not a release attestation and does not send SMS by itself.
 */
final readonly class V5PublicFactorKindAdapter implements V5PasswordBoundChallengeLifecyclePort
{
    public function __construct(
        private V5PasswordBoundChallengeLifecyclePort $existing,
        private ChallengeKind $kind,
    ) {
        if (!in_array($kind, [ChallengeKind::TOTP, ChallengeKind::SMS], true)) {
            throw new \InvalidArgumentException('v5_native_factor_kind_unavailable');
        }
    }

    public function begin(
        int $userId, string $deviceId, string $platform, ChallengeKind $kind
    ): PendingLoginChallenge {
        throw new AuthenticationRejected('challenge_unavailable');
    }

    public function beginBound(
        PasswordVerifiedAccount $account,
        #[\SensitiveParameter] string $login,
        #[\SensitiveParameter] string $password,
        string $deviceId,
        string $platform,
        ChallengeKind $kind,
    ): PendingLoginChallenge {
        if ($kind !== $this->kind) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        return $this->existing->beginBound(
            $account, $login, $password, $deviceId, $platform, $kind
        );
    }

    public function resendSms(
        string $challengeId, string $deviceId, string $platform
    ): PendingLoginChallenge {
        if ($this->kind !== ChallengeKind::SMS) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        return $this->existing->resendSms($challengeId, $deviceId, $platform);
    }
}
