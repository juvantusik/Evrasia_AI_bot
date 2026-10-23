<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';
require_once __DIR__ . '/V5LegalConsentContract.php';
require_once __DIR__ . '/V5PublicLegalCodeValidation.php';

use JsonException;
use Throwable;

final readonly class V5HttpReply
{
    public function __construct(
        public int $status,
        public array $body,
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status');
        }
    }
}

/**
 * Optional STAGING-ONLY strong V5 operations. Never supplied by the
 * current production /api/v5/router.php, which remains HTTP 503.
 * Implementations MUST verify tokens, epoch, revocation, and locked
 * bonus-PIN reader state independently of any client JSON claims.
 */
interface V5SessionActionsPort
{
    public function rotateRefresh(string $refreshToken): AuthenticatedV5Session;
    public function readBonusPin(string $accessJwt): ?string;
}

/**
 * Token verification is strictly server-backed: cryptographic validation,
 * committed strong session, current user state, revocation and AUTH_EPOCH.
 * Missing native proof MUST NOT be interpreted as a valid JWT.
 */
interface V5SessionLogoutPort
{
    /**
     * Revoke precisely this verified V5 access session; reject stale,
     * V4, expired or re-used bearers. Never accept client-supplied user IDs.
     */
    public function revoke(#[\SensitiveParameter] string $accessJwt): bool;
}

/**
 * Server-validated V5 cart GET, no caller-supplied FUSER or user ID.
 * The native business-service adapter is required; no V4-controller/JWT call.
 */
interface V5CartHttpReaderPort
{
    /** @return array{items:list<array<string,mixed>>,total:array<string,mixed>}|null */
    public function readVerifiedCart(#[\SensitiveParameter] string $accessJwt): ?array;
}

interface V5TokenValidationPort
{
    public function isValidAccessToken(string $accessJwt): bool;
}

/**
 * Trusted, public legal-document catalog from current server-side revisions.
 * It has no user identity or accepted flags, and it never stores consent.
 */
interface V5PublicLegalConsentsPort
{
    /** @return list<array<string,mixed>> */
    public function currentPublicDocuments(): array;
}

/**
 * Offline JSON transport adapter for the V5 login workflow.
 * The actual website /api/v5/router.php remains disabled (503), and no
 * Bitrix/password, OTP, SMS, session or token backend is wired here.
 *
 * A future HTTP entrypoint must enforce HTTPS, POST for auth, GET for public legal, content type,
 * request size limits, Cache-Control:no-store and no credential logging.
 */
final readonly class V5AuthHttpHandler
{
    public function __construct(
        private ?V5LoginWorkflow $workflow = null,
        private ?V5SessionActionsPort $sessionActions = null,
        private ?V5TokenValidationPort $tokenValidator = null,
        private ?V5PublicLegalConsentsPort $publicLegalConsents = null,
        private ?V5SessionLogoutPort $logout = null,
        private ?V5CartHttpReaderPort $cartReader = null,
    ) {}

    /**
     * $action MUST come from server-side V5RouteMap dispatch, never a client
     * supplied body attribute. Unsupported actions remain unavailable.
     */
    public function handleJson(string $action, string $rawJson): V5HttpReply
    {
        if (strlen($rawJson) > 8192 || $rawJson === '') {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        try {
            $body = json_decode($rawJson, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                return new V5HttpReply(400, ['error' => 'invalid_request']);
            }

            return match ($action) {
                'signin' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->signIn($body),
                'verifyChallenge' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->verify($body),
                'resendChallenge' => $this->workflow === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->resend($body),
                // Without explicit verified V5 operations, HTTP remains 503.
                'refresh' => $this->sessionActions === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->refresh($body),
                'logout' => $this->logout === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->logout($body),
                'showBonusPin' => $this->sessionActions === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->showBonusPin($body),
                'validateToken' => $this->tokenValidator === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->validateToken($body),
                'validateLegalConsents' => $this->publicLegalConsents === null
                    ? new V5HttpReply(503, ['error' => 'v5_unavailable'])
                    : $this->validateLegalConsents($body),
                default => new V5HttpReply(503, ['error' => 'v5_unavailable']),
            };
        } catch (AuthenticationRejected $e) {
            // Do not expose whether the account exists, which factor failed,
            // retry counts, current SMS limits or the upstream exception.
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        } catch (JsonException|\InvalidArgumentException $e) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        } catch (Throwable $e) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * GET-only public document catalog. Never returns user-specific acceptance
     * and never writes to b_consent_user_consent. No JWT or cookie is required.
     */
    public function handlePublicLegalConsents(): V5HttpReply
    {
        if ($this->publicLegalConsents === null) {
            return new V5HttpReply(503, ['error' => 'v5_unavailable']);
        }
        try {
            $documents = V5LegalConsentContract::publicDocuments(
                $this->publicLegalConsents->currentPublicDocuments()
            );
            if ($documents === null) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            return new V5HttpReply(200, ['documents' => $documents]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    /**
     * Protected GET /cart: the real V5 JWT MUST pass locked user, epoch,
     * assurance and session checks BEFORE calling the native cart service.
     */
    public function handleProtectedCartGet(#[\SensitiveParameter] string $accessJwt): V5HttpReply
    {
        if ($this->cartReader === null) {
            return new V5HttpReply(503, ['error' => 'v5_unavailable']);
        }
        if (strlen($accessJwt) < 110 || strlen($accessJwt) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/D', $accessJwt) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        try {
            $cart = $this->cartReader->readVerifiedCart($accessJwt);
            if ($cart === null) {
                return new V5HttpReply(401, ['error' => 'authentication_failed']);
            }
            if (!is_array($cart) || count($cart) !== 2
                || !isset($cart['items'], $cart['total'])
                || !is_array($cart['items']) || !array_is_list($cart['items'])
                || !is_array($cart['total']) || array_is_list($cart['total'])) {
                return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
            }
            // Price/discount totals are directly calculated by installed V4.
            return new V5HttpReply(200, [
                'items' => $cart['items'], 'total' => $cart['total']
            ]);
        } catch (Throwable) {
            return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
        }
    }

    private function validateLegalConsents(array $body): V5HttpReply
    {
        $result = V5PublicLegalCodeValidation::evaluate(
            $body, $this->publicLegalConsents->currentPublicDocuments()
        );
        return new V5HttpReply($result['status'], $result['body']);
    }

    private function logout(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token'])
            || !self::isString($body, 'access_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $jwt = $body['access_token'];
        if (strlen($jwt) < 110 || strlen($jwt) > 4096
            || substr_count($jwt, '.') !== 2) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        return $this->logout->revoke($jwt)
            ? new V5HttpReply(200, ['status' => 'logged_out'])
            : new V5HttpReply(401, ['error' => 'authentication_failed']);
    }

    private function validateToken(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token'])
            || !self::isString($body, 'access_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $token = $body['access_token'];
        if (strlen($token) < 110 || strlen($token) > 4096
            || substr_count($token, '.') !== 2) {
            return new V5HttpReply(401, ['valid' => false]);
        }
        return $this->tokenValidator->isValidAccessToken($token)
            ? new V5HttpReply(200, ['valid' => true])
            : new V5HttpReply(401, ['valid' => false]);
    }

    private function signIn(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['login','password','device_id','platform','trust_token'])
            || !self::isString($body, 'login')
            || !self::isString($body, 'password')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
            || (array_key_exists('trust_token', $body)
                && $body['trust_token'] !== null
                && !is_string($body['trust_token']))
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $outcome = $this->workflow->signIn(
            $body['login'],
            $body['password'],
            $body['device_id'],
            $body['platform'],
            $body['trust_token'] ?? null,
        );

        if ($outcome instanceof PendingLoginChallenge) {
            return self::pending($outcome, 'challenge_required');
        }
        if ($outcome instanceof AuthenticatedV5Session) {
            return self::authenticated($outcome);
        }
        return new V5HttpReply(503, ['error' => 'temporarily_unavailable']);
    }

    private function verify(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['challenge_id','code','device_id','platform'])
            || !self::isString($body, 'challenge_id')
            || !self::isString($body, 'code')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        return self::authenticated(
            $this->workflow->verifyChallenge(
                $body['challenge_id'],
                $body['code'],
                $body['device_id'],
                $body['platform'],
            )
        );
    }

    private function resend(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['challenge_id','device_id','platform'])
            || !self::isString($body, 'challenge_id')
            || !self::isString($body, 'device_id')
            || !self::isString($body, 'platform')
        ) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        return self::pending(
            $this->workflow->resendSms(
                $body['challenge_id'],
                $body['device_id'],
                $body['platform'],
            ),
            'challenge_pending'
        );
    }

    private function refresh(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['refresh_token'])
            || !self::isString($body, 'refresh_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        if (preg_match('/\Av5r_[a-f0-9]{64}\z/D', $body['refresh_token']) !== 1) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        return self::authenticated(
            $this->sessionActions->rotateRefresh($body['refresh_token'])
        );
    }

    private function showBonusPin(array $body): V5HttpReply
    {
        if (!self::hasOnlyKeys($body, ['access_token'])
            || !self::isString($body, 'access_token')) {
            return new V5HttpReply(400, ['error' => 'invalid_request']);
        }
        $token = $body['access_token'];
        if (strlen($token) > 4096 || substr_count($token, '.') !== 2) {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        $pin = $this->sessionActions->readBonusPin($token);
        if ($pin === null || $pin === '') {
            return new V5HttpReply(401, ['error' => 'authentication_failed']);
        }
        // No additional SMS after an already-verified strong V5 session.
        // A real entrypoint MUST send Cache-Control:no-store and never log PINs.
        return new V5HttpReply(200, ['status' => 'revealed', 'pin' => $pin]);
    }

    private static function pending(PendingLoginChallenge $ticket, string $status): V5HttpReply
    {
        return new V5HttpReply(202, [
            'status' => $status,
            'challenge_id' => $ticket->challengeId,
            'challenge_type' => $ticket->kind->value,
            'expires_in' => $ticket->expiresInSeconds,
        ]);
    }

    private static function authenticated(AuthenticatedV5Session $session): V5HttpReply
    {
        $data = [
            'status' => 'authenticated',
            'access_token' => $session->accessToken,
            'refresh_token' => $session->refreshToken,
        ];
        if ($session->trustedDevice !== null) {
            $data['trusted_device'] = $session->trustedDevice;
        }
        return new V5HttpReply(200, $data);
    }

    private static function isString(array $body, string $key): bool
    {
        return array_key_exists($key, $body) && is_string($body[$key]);
    }

    private static function hasOnlyKeys(array $body, array $allowed): bool
    {
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }
        return true;
    }
}
