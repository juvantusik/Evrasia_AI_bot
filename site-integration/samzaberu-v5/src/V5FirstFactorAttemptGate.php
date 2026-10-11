<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5SiteAccountReader.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * This port MUST reserve ALL supplied windows atomically. On exhaustion it
 * returns false without committing any increments. On outage it MUST throw.
 */
interface V5AtomicAttemptWindowStorePort
{
    /** @param list<V5AttemptWindow> $windows */
    public function reserveAll(array $windows): bool;
}

interface V5AttemptClockPort
{
    public function unixSeconds(): int;
}

final readonly class V5SystemAttemptClock implements V5AttemptClockPort
{
    public function unixSeconds(): int
    {
        return time();
    }
}

/** Explicit deployment configuration: no guessed default quotas. */
final readonly class V5AttemptLimit
{
    public function __construct(
        public int $maxAttempts,
        public int $windowSeconds,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 1000000
            || $windowSeconds < 1 || $windowSeconds > 2592000
        ) {
            throw new InvalidArgumentException('Invalid first-factor budget');
        }
    }
}

final readonly class V5AttemptBudgetPolicy
{
    public const SCOPES = ['login', 'ip', 'device', 'ip_login'];

    /** @var array<string,V5AttemptLimit> */
    public array $limits;

    /** @param array<string,V5AttemptLimit> $limits */
    public function __construct(array $limits)
    {
        if (count($limits) !== count(self::SCOPES)) {
            throw new InvalidArgumentException('All first-factor budgets must be configured');
        }
        foreach (self::SCOPES as $scope) {
            if (!isset($limits[$scope]) || !$limits[$scope] instanceof V5AttemptLimit) {
                throw new InvalidArgumentException('Missing first-factor budget');
            }
        }
        foreach (array_keys($limits) as $scope) {
            if (!in_array($scope, self::SCOPES, true)) {
                throw new InvalidArgumentException('Unexpected first-factor budget');
            }
        }
        $this->limits = $limits;
    }
}

/**
 * Immutable request context, built once by an HTTPS router. The IP MUST come
 * from server-observed REMOTE_ADDR or an explicitly trusted reverse-proxy
 * resolver; NEVER trust caller-provided X-Forwarded-For unconditionally.
 * deviceId/platform are untrusted user inputs used ONLY as extra rate keys.
 */
final readonly class V5AttemptRequestContext
{
    public string $clientIp;

    public function __construct(
        string $clientIp,
        public string $deviceId,
        public string $platform,
    ) {
        $packed = @inet_pton($clientIp);
        if ($packed === false
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
        ) {
            throw new InvalidArgumentException('Invalid first-factor request context');
        }
        // Collapse IPv4-mapped IPv6 addresses into their IPv4 identity.
        if (strlen($packed) === 16
            && substr($packed, 0, 12) === (str_repeat("\0", 10) . "\xff\xff")
        ) {
            $packed = substr($packed, 12);
        }
        $canonical = inet_ntop($packed);
        if (!is_string($canonical) || $canonical === '') {
            throw new InvalidArgumentException('Invalid source address');
        }
        $this->clientIp = $canonical;
    }

    /** Ignores all request headers, including untrusted X-Forwarded-For. */
    public static function fromServerRemoteAddr(array $server, string $deviceId, string $platform): self
    {
        $remote = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($remote)) {
            throw new InvalidArgumentException('Server-observed client IP unavailable');
        }
        return new self($remote, $deviceId, $platform);
    }
}

/** One opaque fixed time-window identifier; no PII or secrets. */
final readonly class V5AttemptWindow
{
    public function __construct(
        public string $bucketKey,
        public int $maxAttempts,
        public int $windowExpiresAt,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $bucketKey) !== 1
            || $maxAttempts < 1 || $maxAttempts > 1000000
            || $windowExpiresAt < 1
        ) {
            throw new InvalidArgumentException('Invalid attempt window');
        }
    }
}

/**
 * Reuse the already accepted V4-compatible phone-login normalizer for the
 * anti-bruteforce key; an IP budget is independent of caller-controlled names.
 */
final readonly class V5AccountLoginRateKey
{
    public function __construct(private V5PhoneLoginNormalizerPort $phones) {}

    public function normalize(string $login): ?string
    {
        $login = trim($login);
        if ($login === '' || strlen($login) > 320) {
            return null;
        }
        if (str_contains($login, '@')) {
            // Bitrix/site email identity differences must be verified before
            // integration. This canonicalization intentionally rejects whitespace.
            if (preg_match('/\s/', $login) === 1) {
                return null;
            }
            return 'email:' . strtolower($login);
        }
        $phone = $this->phones->normalizeForLookup($login);
        if (!is_string($phone) || $phone === '' || strlen($phone) > 320) {
            return null;
        }
        return 'phone:' . $phone;
    }
}

/**
 * Per-request first-factor gate. Does not open DB connections, issue JWTs,
 * or authorize Bitrix sessions. All four quotas must reserve atomically.
 */
final readonly class V5AtomicFirstFactorAttemptGate implements V5FirstFactorAttemptGatePort
{
    public function __construct(
        private V5AtomicAttemptWindowStorePort $store,
        private V5AccountLoginRateKey $identity,
        private V5AttemptBudgetPolicy $policy,
        private V5AttemptRequestContext $context,
        private V5AttemptClockPort $clock,
        #[\SensitiveParameter] private string $hmacKey,
    ) {
        if (strlen($hmacKey) < 32) {
            throw new InvalidArgumentException('First-factor HMAC secret unavailable');
        }
    }

    public function reserveAttempt(string $login): bool
    {
        $canonical = $this->identity->normalize($login);
        if ($canonical === null) {
            return false;
        }
        $now = $this->clock->unixSeconds();
        if ($now < 1 || $now > PHP_INT_MAX - 2592000) {
            throw new RuntimeException('Clock unavailable');
        }
        $source = $this->context;
        $items = [
            'login' => $canonical,
            'ip' => $source->clientIp,
            'device' => $source->deviceId . ':' . $source->platform,
            'ip_login' => $source->clientIp . "\0" . $canonical,
        ];

        $windows = [];
        foreach (V5AttemptBudgetPolicy::SCOPES as $scope) {
            $limit = $this->policy->limits[$scope];
            $starts = intdiv($now, $limit->windowSeconds) * $limit->windowSeconds;
            $digest = hash_hmac(
                'sha256',
                "evrasia:v5:attempt:v1\0" . $scope . "\0"
                    . $starts . "\0" . $items[$scope],
                $this->hmacKey,
            );
            $windows[] = new V5AttemptWindow(
                $digest,
                $limit->maxAttempts,
                $starts + $limit->windowSeconds,
            );
        }
        // Every MySQL connection must lock rows in the same order to avoid
        // deadlocks, even when callers supplied plan scopes in other order.
        usort($windows, static fn(V5AttemptWindow $a, V5AttemptWindow $b): int =>
            strcmp($a->bucketKey, $b->bucketKey));
        return $this->store->reserveAll($windows);
    }
}
