<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5BitrixPasswordProofPort.php';
require_once __DIR__ . '/V5PinnedBitrixPasswordComparator.php';
require_once __DIR__ . '/V5BitrixNativePasswordLoginAdmission.php';
require_once __DIR__ . '/V5PdoUserRowSource.php';
require_once __DIR__ . '/V5NativeTotpStatus.php';
require_once __DIR__ . '/V5ExistingLoginPolicy.php';
require_once __DIR__ . '/V5BitrixNativeLoginPolicyLimits.php';
require_once __DIR__ . '/V5NoMfaFallbackPolicy.php';
require_once __DIR__ . '/V5PinnedBitrixMfaPolicies.php';
require_once __DIR__ . '/V5BitrixMfaAdmissionGuards.php';
require_once __DIR__ . '/V5MySqlAttemptWindowStore.php';

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Concrete first-factor composition for the existing installed Bitrix site.
 *
 * Uses native Password::equals() plus pinned native post-hash policy checks
 * (without CUser::Login or setting cookies),
 * the verified physical b_user columns, current Bitrix MFA ORM and global OTP
 * policy, plus the four-scope InnoDB attempt counter.
 *
 * IMPORTANT: Real current lockout rules and per-user mandatory MFA/SMS policy
 * are NOT derivable from global options or LOGIN_ATTEMPTS alone. Both trusted
 * provider ports are REQUIRED; they have no production implementation yet.
 *
 * This factory is feature-only. Not called by live V5 site.php, never issues
 * tokens, sends SMS, creates PHP sessions, or makes a DB connection itself.
 * The second-factor and V5 strong-session issuers must independently recheck
 * account and AUTH_EPOCH under their own locked native transaction.
 */
final class V5BitrixNativeFirstFactorComposition
{
    /**
     * @param array<string,mixed> $server Server-provided REMOTE_ADDR/HTTPS only.
     * @return V5PasswordProofPort
     */
    public static function build(
        PDO $accountReadDb,
        PDO $attemptBudgetDb,
        array $server,
        string $deviceId,
        string $platform,
        V5CurrentLoginControlsPort $loginControls,
        V5PerUserSmsEligibilityPort $userSmsPolicy,
        V5AttemptBudgetPolicy $attemptBudgets,
        #[\SensitiveParameter] string $attemptHmacKey,
    ): V5PasswordProofPort {
        // Keep the owned attempt-counter transaction independent of the
        // account read and any later strong-session/Bitrix transaction.
        if ($accountReadDb === $attemptBudgetDb
            || strlen($attemptHmacKey) < 32
            || strlen($attemptHmacKey) > 1024
            || !self::readyDb($accountReadDb)
            || !self::readyDb($attemptBudgetDb)
            || !self::trustedHttps($server)) {
            throw new InvalidArgumentException('V5 first-factor configuration unavailable');
        }

        // Fixed REMOTE_ADDR only; untrusted X-Forwarded-For, X-Real-IP and
        // Forwarded are deliberately ignored. The IP is a RATE key, not an
        // allowlist of which public mobile users may authenticate.
        try {
            $context = V5AttemptRequestContext::fromServerRemoteAddr(
                $server, $deviceId, $platform
            );
        } catch (Throwable) {
            throw new InvalidArgumentException('V5 first-factor request context unavailable');
        }

        $phones = new V5NativePhoneLoginNormalizer();
        $globalOtp = new V5PinnedBitrixGlobalOtpConfig();
        $mfa = new V5NativeTotpEnabledGate(
            new V5NativeTotpStatus(
                new V5PinnedBitrixMfaRowSource(),
                new V5StrictNoMfaFallbackPolicy(
                    $globalOtp,
                    new V5MandatoryRightsSmsEligibility(
                        new V5PinnedBitrixMandatoryRightsProbe(),
                        $userSmsPolicy,
                    ),
                ),
            ),
            $globalOtp,
        );
        $reader = new V5SiteAccountReader(
            new V5PdoUserRowSource($accountReadDb),
            $phones,
            $mfa,
            new V5StrictExistingLoginPolicy(
                new V5NativeCappedLoginControls(
                    $loginControls,
                    new V5PinnedBitrixLoginAttemptLimits(),
                ),
            ),
        );
        $attemptGate = new V5AtomicFirstFactorAttemptGate(
            new V5MySqlAttemptWindowStore($attemptBudgetDb),
            new V5AccountLoginRateKey($phones),
            $attemptBudgets,
            $context,
            new V5SystemAttemptClock(),
            $attemptHmacKey,
        );
        return new V5BitrixPasswordProofPort(
            $reader,
            $attemptGate,
            new V5PinnedBitrixPasswordComparator(),
            new V5PinnedBitrixPasswordLoginAdmission(),
        );
    }

    private static function readyDb(PDO $db): bool
    {
        try {
            return $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                && $db->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
                && !$db->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $server */
    private static function trustedHttps(array $server): bool
    {
        $value = $server['HTTPS'] ?? null;
        return is_string($value) && in_array(strtolower($value), ['on', '1'], true);
    }
}
