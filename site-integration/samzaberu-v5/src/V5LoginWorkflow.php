<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/AuthDecision.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * Public controllers must map all AuthenticationRejected exceptions to generic
 * errors, without exposing account existence, challenge state, or secrets.
 */
final class AuthenticationRejected extends RuntimeException {}

/**
 * Returned only by an adapter which has proved the password WITHOUT logging in
 * a Bitrix session or minting any bearer token.
 */
final readonly class PasswordVerifiedAccount
{
    public function __construct(
        public int $userId,
        public bool $accountAllowed,
        public bool $nativeTotpActive,
    ) {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Invalid account identifier');
        }
    }
}

final readonly class MobileTrustAssessment
{
    public function __construct(
        public bool $tokenValid,
        public bool $strongEnrollmentProven,
    ) {}
}

enum ChallengeKind: string
{
    case TOTP = 'totp';
    case SMS = 'sms';
}

/** A pending challenge NEVER contains access/refresh credentials. */
interface V5LoginResult {}

final readonly class PendingLoginChallenge implements V5LoginResult
{
    public function __construct(
        public string $challengeId,
        public ChallengeKind $kind,
        public int $expiresInSeconds,
    ) {
        if (!V5LoginWorkflow::validChallengeId($challengeId)
            || $expiresInSeconds < 1
            || $expiresInSeconds > 300
        ) {
            throw new InvalidArgumentException('Invalid pending challenge');
        }
    }
}

/** Credentials can be supplied ONLY by a V5StrongSessionPort. */
final readonly class AuthenticatedV5Session implements V5LoginResult
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public ?array $trustedDevice = null,
    ) {
        if ($accessToken === '' || $refreshToken === '') {
            throw new InvalidArgumentException('Incomplete V5 session');
        }
    }
}

/**
 * A trusted adapter emits this only after atomic, one-time OTP/TOTP validation
 * against a server-side password-proven, device-bound challenge. Do not expose
 * an API that constructs it from JSON, POST parameters, or unverified JWTs.
 */
final readonly class ConsumedLoginChallenge
{
    public function __construct(
        public int $userId,
        public string $deviceId,
        public string $platform,
        public ChallengeKind $kind,
        public string $grantReference,
    ) {
        if ($userId <= 0
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || !V5LoginWorkflow::validChallengeId($grantReference)
        ) {
            throw new InvalidArgumentException('Invalid verified challenge grant');
        }
    }
}

/** Verify a password without authorizing the current PHP/Bitrix session. */
interface V5PasswordProofPort
{
    public function verifyWithoutLogin(string $login, string $password): ?PasswordVerifiedAccount;
}

/**
 * Both facts must be checked server-side against current trust token version,
 * token hash, account, device binding, revoked status and strong enrollment.
 * Old V4 password-only registrations MUST NOT count as strong enrollment.
 */
interface V5TrustProofPort
{
    public function evaluate(
        int $userId,
        string $deviceId,
        string $platform,
        string $trustToken,
    ): MobileTrustAssessment;
}

/**
 * Production implementation MUST:
 * - atomically create password-proven challenges and reserve SMS quotas;
 * - send SMS ONLY to the existing verified account phone after quotas pass;
 * - increment attempts on every code check, enforce TTL/lockouts, and ensure
 *   at most ONE successful code verification under concurrent requests;
 * - use native Bitrix TOTP + its replay state, NOT a separate TOTP secret;
 * - keep SMS codes private and hashed with a server-side secret;
 * - reserve resend quotas atomically before sending; never extend original TTL;
 * - return a consumed grant only after account/session/MFA re-checks pass.
 * If any store, gateway, rate limiter, or verification backend is unavailable,
 * throw/reject; NEVER claim a challenge is verified or send tokens.
 */
interface V5AtomicChallengePort
{
    public function begin(
        int $userId,
        string $deviceId,
        string $platform,
        ChallengeKind $kind,
    ): PendingLoginChallenge;

    public function verifyAndConsume(
        string $challengeId,
        string $code,
        string $deviceId,
        string $platform,
    ): ?ConsumedLoginChallenge;

    public function resendSms(
        string $challengeId,
        string $deviceId,
        string $platform,
    ): PendingLoginChallenge;
}

/**
 * Issue V5-only JWTs and server-verifiable assurance after rechecking the
 * account's current allowed status, auth epoch, and prior proof source.
 * A V4 access/refresh token MUST NEVER be accepted here. On any failure do
 * not return partially issued credentials. Implement independent V5 refresh.
 */
interface V5StrongSessionPort
{
    public function afterTrustedDevice(
        PasswordVerifiedAccount $account,
        string $deviceId,
        string $platform,
        string $trustToken,
    ): AuthenticatedV5Session;

    public function afterConsumedChallenge(
        ConsumedLoginChallenge $grant,
    ): AuthenticatedV5Session;
}

/**
 * Pure application-level coordinator. The V5 HTTP router remains 503-disabled
 * until all site-specific, fail-closed production port implementations exist.
 */
final readonly class V5LoginWorkflow
{
    public function __construct(
        private V5PasswordProofPort $passwords,
        private V5TrustProofPort $trust,
        private V5AtomicChallengePort $challenges,
        private V5StrongSessionPort $sessions,
    ) {}

    public function signIn(
        string $login,
        string $password,
        string $deviceId,
        string $platform,
        ?string $trustToken = null,
    ): V5LoginResult {
        self::requireInstallation($deviceId, $platform);
        $login = trim($login);
        if ($login === '' || strlen($login) > 320
            || $password === '' || strlen($password) > 4096
        ) {
            throw new AuthenticationRejected('authentication_failed');
        }
        $account = $this->passwords->verifyWithoutLogin($login, $password);
        if ($account === null || !$account->accountAllowed) {
            throw new AuthenticationRejected('authentication_failed');
        }

        // Mandatory TOTP has priority over any existing trusted-device record.
        if ($account->nativeTotpActive) {
            return $this->requireChallenge(
                $account->userId, $deviceId, $platform, ChallengeKind::TOTP
            );
        }

        $validTrust = false;
        $mfaEnrolled = false;
        if ($trustToken !== null && $trustToken !== '' && strlen($trustToken) <= 1024) {
            $assessment = $this->trust->evaluate(
                $account->userId, $deviceId, $platform, $trustToken
            );
            $validTrust = $assessment->tokenValid;
            $mfaEnrolled = $assessment->strongEnrollmentProven;
        }
        $decision = AuthDecision::afterPassword(
            true, true, false, $validTrust, $mfaEnrolled
        );

        if ($decision === NextFactor::COMPLETE) {
            // Issuer MUST independently recheck trust evidence, account state,
            // and assurance before minting a server-side V5 session.
            return $this->sessions->afterTrustedDevice(
                $account, $deviceId, $platform, (string)$trustToken
            );
        }
        if ($decision === NextFactor::SMS) {
            return $this->requireChallenge(
                $account->userId, $deviceId, $platform, ChallengeKind::SMS
            );
        }
        throw new AuthenticationRejected('authentication_failed');
    }

    public function verifyChallenge(
        string $challengeId,
        string $code,
        string $deviceId,
        string $platform,
    ): AuthenticatedV5Session {
        self::requireInstallation($deviceId, $platform);
        if (!self::validChallengeId($challengeId)
            || preg_match('/\A[0-9]{6}\z/D', $code) !== 1
        ) {
            throw new AuthenticationRejected('verification_failed');
        }

        $grant = $this->challenges->verifyAndConsume(
            $challengeId, $code, $deviceId, $platform
        );
        if ($grant === null
            || $grant->deviceId !== $deviceId
            || $grant->platform !== $platform
        ) {
            throw new AuthenticationRejected('verification_failed');
        }

        // A consumed grant is the ONLY valid second-factor proof accepted here.
        return $this->sessions->afterConsumedChallenge($grant);
    }

    public function resendSms(
        string $challengeId,
        string $deviceId,
        string $platform,
    ): PendingLoginChallenge {
        self::requireInstallation($deviceId, $platform);
        if (!self::validChallengeId($challengeId)) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        $ticket = $this->challenges->resendSms(
            $challengeId, $deviceId, $platform
        );
        if ($ticket->kind !== ChallengeKind::SMS
            || !hash_equals($challengeId, $ticket->challengeId)
        ) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        return $ticket;
    }

    public static function validInstallation(string $deviceId, string $platform): bool
    {
        return preg_match('/\Asz_[a-f0-9]{64}\z/D', $deviceId) === 1
            && in_array($platform, ['ios', 'android'], true);
    }

    public static function validChallengeId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $id) === 1;
    }

    private static function requireInstallation(string $deviceId, string $platform): void
    {
        if (!self::validInstallation($deviceId, $platform)) {
            throw new AuthenticationRejected('invalid_device');
        }
    }

    private function requireChallenge(
        int $userId,
        string $deviceId,
        string $platform,
        ChallengeKind $kind,
    ): PendingLoginChallenge {
        $ticket = $this->challenges->begin($userId, $deviceId, $platform, $kind);
        if ($ticket->kind !== $kind) {
            throw new AuthenticationRejected('challenge_unavailable');
        }
        return $ticket;
    }
}
