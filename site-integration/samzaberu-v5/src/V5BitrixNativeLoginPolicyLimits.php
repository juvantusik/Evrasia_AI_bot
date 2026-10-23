<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5ExistingLoginPolicy.php';

use PDO;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Native CUser::LoginInternal (installed SHA pinned below) reads three
 * independent policy values:
 * - getLoginAttempts(): CAPTCHA after next LOGIN_ATTEMPTS exceeds threshold;
 * - getBlockLoginAttempts() and getBlockTime(): wrong-password block trigger.
 *
 * V5 never invokes LoginInternal, CAPTCHA, blockUser or event callbacks:
 * those may mutate state. A read-only native getPolicy() is used only
 * after the exact installed CUser source and public static method are checked.
 */
final readonly class V5NativeLoginAttemptLimits
{
    public function __construct(
        public int $captchaThreshold,
        public int $blockThreshold,
        public int $blockSeconds,
    ) {
        foreach ([$captchaThreshold, $blockThreshold, $blockSeconds] as $n) {
            if ($n < 0 || $n > 1000000000) {
                throw new \InvalidArgumentException('V5 native attempt policy unavailable');
            }
        }
    }

    /**
     * Block BEFORE a CAPTCHA that V5 cannot solve, and conservatively before
     * a configured password-failure block threshold. Zero means native rule
     * is disabled; it is never replaced by a guessed default.
     */
    public function maximumAllowedExistingFailures(): ?int
    {
        $caps = [];
        if ($this->captchaThreshold > 0) {
            $caps[] = $this->captchaThreshold;
        }
        if ($this->blockThreshold > 0 && $this->blockSeconds > 0) {
            $caps[] = $this->blockThreshold;
        }
        return $caps === [] ? null : min($caps);
    }
}

interface V5NativeLoginAttemptLimitsPort
{
    public function readForUser(int $userId): ?V5NativeLoginAttemptLimits;
}

/**
 * Production-source-pinned, read-only *candidate*, not native acceptance.
 * getPolicy is NOT a replacement for site admission rules, password expiry,
 * a current timed-block check or custom OnBeforeUserLogin restrictions.
 *
 * A Bitrix update, inaccessible getPolicy, malformed values or an exception
 * returns UNKNOWN and prevents this adapter from authorizing a login.
 */
final class V5PinnedBitrixLoginAttemptLimits implements V5NativeLoginAttemptLimitsPort
{
    private const USER_SUFFIX = '/bitrix/modules/main/classes/general/user.php';
    private const USER_SHA256 = 'bd591263fc4f5c6b634f6f8d802a6d00cca82ba4271d3f08f1f02532d0c6cc99';

    public function readForUser(int $userId): ?V5NativeLoginAttemptLimits
    {
        if ($userId < 1 || $userId > 2147483647) return null;
        try {
            if (!class_exists(\CUser::class)
                || !is_callable([\CUser::class, 'getPolicy'])) return null;

            $reflection = new ReflectionClass(\CUser::class);
            $file = $reflection->getFileName();
            $method = new ReflectionMethod(\CUser::class, 'getPolicy');
            if (!$method->isPublic() || !$method->isStatic()
                || !is_string($file) || !str_ends_with($file, self::USER_SUFFIX)
                || !is_file($file) || is_link($file)
                || realpath($file) !== $file
                || @hash_file('sha256', $file) !== self::USER_SHA256) {
                return null;
            }

            $policy = \CUser::getPolicy($userId);
            if (!is_object($policy)) return null;

            $values = [];
            foreach (['getLoginAttempts', 'getBlockLoginAttempts', 'getBlockTime'] as $getter) {
                if (!is_callable([$policy, $getter])) return null;
                $value = $policy->$getter();
                // Bitrix casts these to int; we instead reject corrupted,
                // negative and ambiguous numeric values rather than assume 0.
                if (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1) {
                    $value = filter_var($value, FILTER_VALIDATE_INT, [
                        'options' => ['min_range' => 0, 'max_range' => 1000000000],
                    ]);
                }
                if (!is_int($value) || $value < 0 || $value > 1000000000) {
                    return null;
                }
                $values[] = $value;
            }
            return new V5NativeLoginAttemptLimits(...$values);
        } catch (Throwable) {
            return null;
        }
    }
}

/**
 * The existing *independent* site-policy port MUST still positively confirm
 * temporary locks, security restrictions, password expiry and event rules.
 * This decorator can only TIGHTEN its attempt limit using verified native
 * CUser policy. It cannot turn an unknown or rejected site condition into YES.
 *
 * Existing V5 four-window anti-bruteforce rate limiter remains mandatory.
 */
final readonly class V5NativeCappedLoginControls implements V5CurrentLoginControlsPort
{
    public function __construct(
        private V5CurrentLoginControlsPort $site,
        private V5NativeLoginAttemptLimitsPort $native,
    ) {}

    public function readForUser(int $userId): ?V5CurrentLoginControls
    {
        if ($userId < 1) return null;
        try {
            $site = $this->site->readForUser($userId);
            if (!$site instanceof V5CurrentLoginControls) return null;
            $native = $this->native->readForUser($userId);
            if (!$native instanceof V5NativeLoginAttemptLimits) return null;

            $ceiling = $site->maxFailedAttempts;
            $nativeCap = $native->maximumAllowedExistingFailures();
            if ($ceiling !== null && $nativeCap !== null) {
                $ceiling = min($ceiling, $nativeCap);
            }
            // If native policy has no CAPTCHA/block threshold, retain the
            // explicit site cap; the independent four-window V5 rate budget
            // is still required. If both are absent, existing strict policy
            // rejects the unknown ceiling instead of assuming infinity.
            return new V5CurrentLoginControls(
                $ceiling,
                $site->temporaryLockClear,
                $site->securityRestrictionClear,
                $site->siteLoginRulesClear,
            );
        } catch (Throwable) {
            return null;
        }
    }
}
