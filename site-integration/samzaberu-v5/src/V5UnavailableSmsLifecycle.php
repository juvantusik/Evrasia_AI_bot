<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';

/**
 * Email-first V5 can be assembled without enabling mobile SMS.
 *
 * This is a denial-only boundary, NOT an alternative SMS issuer,
 * dispatcher, session shim, rate limiter, or OTP algorithm. A real
 * site-native SMS recovery provider must be separately attested.
 */
final readonly class V5UnavailableSmsLifecycle implements V5PasswordBoundChallengeLifecyclePort
{
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
        throw new AuthenticationRejected('challenge_unavailable');
    }

    public function resendSms(
        string $challengeId, string $deviceId, string $platform
    ): PendingLoginChallenge {
        throw new AuthenticationRejected('challenge_unavailable');
    }
}
