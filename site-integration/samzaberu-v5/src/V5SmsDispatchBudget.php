<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5FirstFactorAttemptGate.php';

use InvalidArgumentException;
use RuntimeException;

/**
 * SMS budgets are deliberately SEPARATE from password-attempt budgets.
 * A password-proven challenge is required, and the phone number is resolved
 * from current server-side verification state; no HTTP-supplied phone is used.
 */
interface V5VerifiedSmsDestinationPort
{
    /** Must throw on a verification-store outage, not return a stale fallback. */
    public function forUser(int $userId): ?V5VerifiedSmsDestination;
}

/** The stamp represents the authoritative verification row/version/epoch. */
final readonly class V5VerifiedSmsDestination
{
    public function __construct(
        public int $userId,
        public string $e164,
        public string $verificationStamp,
    ) {
        if ($userId < 1
            || preg_match('/\A\+[1-9][0-9]{7,14}\z/D', $e164) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $verificationStamp) !== 1
        ) {
            throw new InvalidArgumentException('Invalid verified SMS destination');
        }
    }
}

/**
 * Maximum, rather than typical, provider charge for ONE message in a
 * specified currency's minor units. Quote must be a genuine safe upper bound
 * for the exact verified destination and current provider contract.
 */
final readonly class V5SmsPriceCeiling
{
    public function __construct(
        public int $maxMinorUnits,
        public string $currency,
    ) {
        if ($maxMinorUnits < 1 || $maxMinorUnits > 1000000000
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
        ) {
            throw new InvalidArgumentException('Invalid SMS price ceiling');
        }
    }
}

interface V5SmsPriceCeilingPort
{
    /** Throw when an authoritative maximum tariff cannot be determined. */
    public function upperBound(V5VerifiedSmsDestination $destination): ?V5SmsPriceCeiling;
}

/** Every quota, including global cost, must be deliberately configured. */
final readonly class V5SmsLimit
{
    public function __construct(
        public int $maxUnits,
        public int $windowSeconds,
    ) {
        if ($maxUnits < 1 || $maxUnits > 1000000000
            || $windowSeconds < 1 || $windowSeconds > 2592000
        ) {
            throw new InvalidArgumentException('Invalid SMS quota');
        }
    }
}

final readonly class V5SmsBudgetPolicy
{
    public const SCOPES = [
        'account_burst', 'account_day',
        'phone_burst', 'phone_day',
        'ip_burst', 'ip_day',
        'device_burst',
        'global_burst', 'global_day', 'global_cost_day',
    ];

    /** @var array<string,V5SmsLimit> */
    public array $limits;

    /** @param array<string,V5SmsLimit> $limits */
    public function __construct(array $limits, public string $currency)
    {
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || count($limits) !== count(self::SCOPES)
        ) {
            throw new InvalidArgumentException('SMS budget configuration incomplete');
        }
        foreach (self::SCOPES as $scope) {
            if (!isset($limits[$scope]) || !$limits[$scope] instanceof V5SmsLimit) {
                throw new InvalidArgumentException('Missing SMS budget scope');
            }
        }
        foreach (array_keys($limits) as $name) {
            if (!in_array($name, self::SCOPES, true)) {
                throw new InvalidArgumentException('Unexpected SMS budget scope');
            }
            // A wrongly configured 'day' window must not silently become
            // a short-lived spend cap, or a burst budget a one-second bypass.
            if ((str_ends_with($name, '_day') && $limits[$name]->windowSeconds < 86400)
                || (str_ends_with($name, '_burst') && $limits[$name]->windowSeconds < 60)
            ) {
                throw new InvalidArgumentException('Unsafe SMS quota window');
            }
        }
        $this->limits = $limits;
    }
}

/** Single opaque counter with explicit charge weight, including money. */
final readonly class V5SmsBudgetWindow
{
    public function __construct(
        public string $bucketKey,
        public int $maxUnits,
        public int $chargeUnits,
        public int $expiresAt,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $bucketKey) !== 1
            || $maxUnits < 1 || $maxUnits > 1000000000
            || $chargeUnits < 1 || $chargeUnits > 1000000000
            || $expiresAt < 1
        ) {
            throw new InvalidArgumentException('Invalid SMS reservation window');
        }
    }
}

/**
 * Only trusted server-side challenge code may construct/send this request.
 * The store must fetch and lock the EXISTING password-proven challenge,
 * recheck device/platform/user binding, epoch, TTL and resend rules, lock
 * phone verification state, reserve ALL quotas, and enqueue ONE encrypted SMS
 * delivery with a durable provider idempotency key in ONE transaction.
 * Never claim an SMS was sent when an outbox record was merely queued.
 */
final readonly class V5SmsQueueReservation
{
    /** @param list<V5SmsBudgetWindow> $windows */
    public function __construct(
        public int $userId,
        public string $challengeId,
        public string $deviceId,
        public string $platform,
        public bool $isResend,
        public V5VerifiedSmsDestination $destination,
        public V5SmsPriceCeiling $price,
        public array $windows,
    ) {
        if ($userId < 1 || $destination->userId !== $userId
            || !V5LoginWorkflow::validChallengeId($challengeId)
            || !V5LoginWorkflow::validInstallation($deviceId, $platform)
            || count($windows) !== count(V5SmsBudgetPolicy::SCOPES)
        ) {
            throw new InvalidArgumentException('Invalid SMS queue reservation');
        }
        $previous = '';
        foreach ($windows as $window) {
            if (!$window instanceof V5SmsBudgetWindow
                || strcmp($window->bucketKey, $previous) <= 0
            ) {
                throw new InvalidArgumentException('Noncanonical SMS window ordering');
            }
            $previous = $window->bucketKey;
        }
    }
}

final readonly class V5SmsQueuedDelivery
{
    public function __construct(public string $deliveryId)
    {
        if (!V5LoginWorkflow::validChallengeId($deliveryId)) {
            throw new InvalidArgumentException('Invalid queued delivery reference');
        }
    }
}

interface V5AtomicSmsOutboxPort
{
    /** Must commit whole challenge update + all quotas + outbox, or NONE. */
    public function reserveAndQueue(V5SmsQueueReservation $reservation): V5SmsQueuedDelivery;
}

/**
 * Staged coordinator, NOT a V5AtomicChallengePort implementation.
 * Security-critical challenge / phone locks and provider idempotency are
 * prerequisites of the future persistent implementation of the outbox port.
 */
final readonly class V5SmsDispatchBudget
{
    public function __construct(
        private V5VerifiedSmsDestinationPort $phones,
        private V5SmsPriceCeilingPort $prices,
        private V5AtomicSmsOutboxPort $outbox,
        private V5SmsBudgetPolicy $policy,
        private V5AttemptClockPort $clock,
        #[\SensitiveParameter] private string $hmacKey,
    ) {
        if (strlen($hmacKey) < 32) {
            throw new InvalidArgumentException('SMS HMAC secret unavailable');
        }
    }

    /**
     * Called only from a password-proven challenge lifecycle, never directly
     * from public request parameters. No phone or estimated cost input exists.
     */
    public function reserveAndQueue(
        int $userId,
        string $challengeId,
        V5AttemptRequestContext $context,
        bool $isResend,
    ): V5SmsQueuedDelivery {
        if ($userId < 1 || !V5LoginWorkflow::validChallengeId($challengeId)) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        $destination = $this->phones->forUser($userId);
        if (!$destination instanceof V5VerifiedSmsDestination
            || $destination->userId !== $userId
        ) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        $price = $this->prices->upperBound($destination);
        if (!$price instanceof V5SmsPriceCeiling
            || $price->currency !== $this->policy->currency
        ) {
            throw new AuthenticationRejected('challenge_unavailable');
        }

        $now = $this->clock->unixSeconds();
        if ($now < 1 || $now > PHP_INT_MAX - 2592000) {
            throw new RuntimeException('SMS clock unavailable');
        }

        $sources = [
            'account_burst' => (string)$userId,
            'account_day' => (string)$userId,
            'phone_burst' => $destination->e164,
            'phone_day' => $destination->e164,
            'ip_burst' => $context->clientIp,
            'ip_day' => $context->clientIp,
            'device_burst' => $context->deviceId . ':' . $context->platform,
            'global_burst' => 'all',
            'global_day' => 'all',
            'global_cost_day' => $this->policy->currency,
        ];

        $windows = [];
        foreach (V5SmsBudgetPolicy::SCOPES as $scope) {
            $limit = $this->policy->limits[$scope];
            $charge = $scope === 'global_cost_day' ? $price->maxMinorUnits : 1;
            if ($charge > $limit->maxUnits) {
                throw new AuthenticationRejected('challenge_unavailable');
            }
            $start = intdiv($now, $limit->windowSeconds) * $limit->windowSeconds;
            $windows[] = new V5SmsBudgetWindow(
                hash_hmac('sha256', "evrasia:v5:sms-budget:v1\0" . $scope . "\0"
                    . $start . "\0" . $sources[$scope], $this->hmacKey),
                $limit->maxUnits,
                $charge,
                $start + $limit->windowSeconds,
            );
        }
        usort($windows, static fn(V5SmsBudgetWindow $left, V5SmsBudgetWindow $right): int =>
            strcmp($left->bucketKey, $right->bucketKey));

        return $this->outbox->reserveAndQueue(new V5SmsQueueReservation(
            $userId, $challengeId, $context->deviceId, $context->platform,
            $isResend, $destination, $price, $windows,
        ));
    }
}
