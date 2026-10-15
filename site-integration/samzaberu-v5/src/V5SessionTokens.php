<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * V5-only HS256 access tokens, independent of V4 keys and V4 JWT handlers.
 * Caller MUST provide committed/revocation-verified V5 session data.
 * This codec does not issue a session, verify an MFA proof or authorize an action.
 */
final readonly class V5AccessJwtCodec
{
    public const ISSUER = 'evrasia-samzaberu-v5';
    public const AUDIENCE = 'samzaberu-v5';

    /** @var array<string,string> */
    private array $keys;

    /** @param array<string,string> $keyRing distinct 32+ byte V5 secrets */
    public function __construct(
        #[\SensitiveParameter] array $keyRing,
        private string $signingKid,
        private int $maxAccessTtlSeconds = 300,
    ) {
        if ($maxAccessTtlSeconds < 30 || $maxAccessTtlSeconds > 900
            || count($keyRing) < 1 || count($keyRing) > 4
            || !self::validKid($signingKid) || !isset($keyRing[$signingKid])
        ) {
            throw new InvalidArgumentException('V5 signing policy unavailable');
        }
        foreach ($keyRing as $kid => $key) {
            if (!is_string($kid) || !self::validKid($kid)
                || !is_string($key) || strlen($key) < 32 || strlen($key) > 1024) {
                throw new InvalidArgumentException('V5 signing policy unavailable');
            }
        }
        $this->keys = $keyRing;
    }

    /** @param array<string,mixed> $committedSession authoritative server state only */
    public function issueForCommittedSession(array $committedSession, int $now): string
    {
        if ($now <= 0 || $now > PHP_INT_MAX - $this->maxAccessTtlSeconds
            || ($committedSession['version'] ?? null) !== 'v5'
            || ($committedSession['status'] ?? null) !== 'active'
            || ($committedSession['revocation_verified'] ?? null) !== true
            || !is_int($committedSession['user_id'] ?? null)
            || $committedSession['user_id'] < 1
            || !is_string($committedSession['sid'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $committedSession['sid']) !== 1
            || !is_int($committedSession['auth_epoch'] ?? null)
            || $committedSession['auth_epoch'] < 1
            || !in_array($committedSession['assurance'] ?? null, ['totp', 'sms', 'trusted'], true)
            || !is_int($committedSession['assured_at'] ?? null)
            || $committedSession['assured_at'] < 1
            || $committedSession['assured_at'] > $now
            || !is_int($committedSession['expires_at'] ?? null)
            || $committedSession['expires_at'] <= $now) {
            throw new RuntimeException('v5_session_unavailable');
        }

        $exp = min($committedSession['expires_at'], $now + $this->maxAccessTtlSeconds);
        $header = ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => $this->signingKid];
        $payload = [
            'iss' => self::ISSUER, 'aud' => self::AUDIENCE,
            'version' => 'v5', 'token_use' => 'access',
            'sub' => (string)$committedSession['user_id'],
            'sid' => $committedSession['sid'],
            'auth_epoch' => $committedSession['auth_epoch'],
            'iat' => $now, 'exp' => $exp,
            'jti' => bin2hex(random_bytes(32)),
        ];
        try {
            $message = self::encode(json_encode($header, JSON_THROW_ON_ERROR))
                . '.' . self::encode(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
            throw new RuntimeException('v5_session_unavailable', 0, $e);
        }
        return $message . '.' . self::encode(
            hash_hmac('sha256', $message, $this->keys[$this->signingKid], true)
        );
    }

    /**
     * Signature-validated V5 access claims only; separately require a fresh
     * server-side revocation+epoch check and V5SessionAssurancePolicy.
     * @return array<string,mixed>|null
     */
    public function verifyAccess(string $jwt, int $now): ?array
    {
        if ($now <= 0 || $now > PHP_INT_MAX - 30
            || strlen($jwt) < 110 || strlen($jwt) > 4096
            || substr_count($jwt, '.') !== 2) return null;

        $parts = explode('.', $jwt);
        if (strlen($parts[0]) > 512 || strlen($parts[1]) > 3072
            || strlen($parts[2]) !== 43) return null;

        $headerBytes = self::decode($parts[0]);
        $signature = self::decode($parts[2]);
        if ($headerBytes === null || strlen($headerBytes) > 384
            || $signature === null || strlen($signature) !== 32) return null;
        try {
            $header = json_decode($headerBytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) { return null; }
        if (!is_array($header) || count($header) !== 3
            || ($header['typ'] ?? null) !== 'JWT'
            || ($header['alg'] ?? null) !== 'HS256'
            || !is_string($header['kid'] ?? null)
            || !self::validKid($header['kid'])
            || !isset($this->keys[$header['kid']])) return null;

        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1],
            $this->keys[$header['kid']], true);
        if (!hash_equals($expected, $signature)) return null;

        $payloadBytes = self::decode($parts[1]);
        if ($payloadBytes === null || strlen($payloadBytes) > 2048) return null;
        try {
            $claims = json_decode($payloadBytes, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) { return null; }

        if (!is_array($claims) || count($claims) !== 10
            || ($claims['iss'] ?? null) !== self::ISSUER
            || ($claims['aud'] ?? null) !== self::AUDIENCE
            || ($claims['version'] ?? null) !== 'v5'
            || ($claims['token_use'] ?? null) !== 'access'
            || !is_string($claims['sub'] ?? null)
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $claims['sub']) !== 1
            || filter_var($claims['sub'], FILTER_VALIDATE_INT) === false
            || !is_string($claims['sid'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $claims['sid']) !== 1
            || !is_int($claims['auth_epoch'] ?? null)
            || $claims['auth_epoch'] < 1
            || !is_int($claims['iat'] ?? null)
            || !is_int($claims['exp'] ?? null)
            || $claims['iat'] < 1
            || $claims['iat'] > $now + 30
            || $claims['iat'] >= $claims['exp']
            || $claims['exp'] <= $now
            || $claims['exp'] - $claims['iat'] > $this->maxAccessTtlSeconds
            || !is_string($claims['jti'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $claims['jti']) !== 1) return null;
        return $claims;
    }

    private static function validKid(string $kid): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,24}\z/D', $kid) === 1;
    }
    private static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    private static function decode(string $s): ?string
    {
        if ($s === '' || preg_match('/\A[A-Za-z0-9_-]+\z/D', $s) !== 1) return null;
        $remainder = strlen($s) % 4;
        if ($remainder === 1) return null;
        $data = base64_decode(strtr($s, '-_', '+/')
            . str_repeat('=', $remainder === 0 ? 0 : 4 - $remainder), true);
        if (!is_string($data) || !hash_equals(self::encode($data), $s)) return null;
        return $data;
    }
}

/**
 * Opaque refresh bearer generation and keyed digest only, NOT a refresh
 * authorization implementation. Persist/rotate/revoke in one DB transaction
 * before ever implementing the /auth/refresh route.
 */
final readonly class V5OpaqueRefreshTokens
{
    public function __construct(#[\SensitiveParameter] private string $separatePepper)
    {
        if (strlen($separatePepper) < 32) {
            throw new InvalidArgumentException('V5 refresh digest key unavailable');
        }
    }

    /** @return array{token:string,digest:string} */
    public function issue(): array
    {
        $token = 'v5r_' . bin2hex(random_bytes(32));
        return ['token' => $token, 'digest' => $this->digest($token)];
    }

    public function digestPresented(string $presented): ?string
    {
        return preg_match('/\Av5r_[a-f0-9]{64}\z/D', $presented) === 1
            ? $this->digest($presented) : null;
    }

    private function digest(string $token): string
    {
        return hash_hmac('sha256', "evrasia:v5:refresh:v1\0" . $token, $this->separatePepper);
    }
}
