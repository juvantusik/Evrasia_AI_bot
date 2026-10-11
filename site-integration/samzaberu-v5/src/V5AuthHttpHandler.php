<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5LoginWorkflow.php';

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
 * Offline JSON transport adapter for the V5 login workflow.
 * The actual website /api/v5/router.php remains disabled (503), and no
 * Bitrix/password, OTP, SMS, session or token backend is wired here.
 *
 * A future HTTP entrypoint must enforce HTTPS, method POST, content type,
 * request size limits, Cache-Control:no-store and no credential logging.
 */
final readonly class V5AuthHttpHandler
{
    public function __construct(private V5LoginWorkflow $workflow) {}

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
                'signin' => $this->signIn($body),
                'verifyChallenge' => $this->verify($body),
                'resendChallenge' => $this->resend($body),
                // V5 refresh and protected PIN require independent V5 session
                // verifier/issuer integration and stay unimplemented.
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
            'challenge_sent'
        );
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
