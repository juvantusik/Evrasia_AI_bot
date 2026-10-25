<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5MySqlChallengeHttpPort.php';

use InvalidArgumentException;
use Throwable;

/**
 * Feature-only, NO SMS transport, NO Bitrix login, NO HTTP route.
 *
 * Reuses TWO separately verified lifecycle providers instead of creating
 * a second SMS challenge algorithm. The existing site SMS challenge and
 * the V5 SQL outbox are NOT interchangeable browser/mobile endpoints.
 *
 * This class deliberately does not implement
 * V5PublicVerifiedFactorLifecyclePort. Public activation still requires
 * real independently attested native TOTP AND SMS dispatch/receipt, plus
 * per-user AUTH_EPOCH/security coverage and the public release gate.
 */
final readonly class V5SeparatedFactorLifecycle implements V5PasswordBoundChallengeLifecyclePort
{
    public function __construct(
        private V5PasswordBoundChallengeLifecyclePort $totp,
        private V5PasswordBoundChallengeLifecyclePort $sms,
        private ?V5PasswordBoundChallengeLifecyclePort $email = null,
    ) {
        if ($totp === $sms || ($email !== null && ($totp === $email || $sms === $email))) {
            throw new InvalidArgumentException('Separate factor issuers required');
        }
    }

    public function begin(
        int $userId, string $deviceId, string $platform, ChallengeKind $kind
    ): PendingLoginChallenge {
        // Unbound account IDs are never sufficient to issue a login challenge.
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
        if (!$account->accountAllowed
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || $login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        // Never route email through the paid SMS provider. Missing verified
        // mobile email lifecycle must fail closed; a website PHP session is
        // not a valid mobile email provider.
        if ($kind === ChallengeKind::EMAIL && $this->email === null) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        // A current native TOTP factor forbids SMS downgrade. A valid strong
        // trusted device has already been handled by V5LoginWorkflow.
        if ($kind === ChallengeKind::TOTP && !$account->nativeTotpActive
            || $kind === ChallengeKind::SMS && $account->nativeTotpActive) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        $issuer = match ($kind) {
            ChallengeKind::TOTP => $this->totp,
            ChallengeKind::SMS => $this->sms,
            ChallengeKind::EMAIL => $this->email,
        };
        try {
            $ticket = $issuer->beginBound(
                $account, $login, $password, $deviceId, $platform, $kind
            );
            if ($ticket->kind !== $kind
                || !V5LoginWorkflow::validChallengeId($ticket->challengeId)) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            return $ticket;
        } catch (Throwable) {
            // No automatic SMS fallback after a TOTP error, and no leaking
            // provider/identity error data to the mobile response.
            throw new AuthenticationRejected('challenge_unavailable');
        }
    }

    public function resendSms(
        string $challengeId, string $deviceId, string $platform
    ): PendingLoginChallenge {
        if (!V5LoginWorkflow::validChallengeId($challengeId)
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        try {
            // Provider must independently re-lock challenge + b_user +
            // native MFA + phone stamp + AUTH_EPOCH before any paid resend.
            $ticket = $this->sms->resendSms($challengeId, $deviceId, $platform);
            if ($ticket->kind !== ChallengeKind::SMS
                || !hash_equals($challengeId, $ticket->challengeId)) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            return $ticket;
        } catch (Throwable) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
    }
}
