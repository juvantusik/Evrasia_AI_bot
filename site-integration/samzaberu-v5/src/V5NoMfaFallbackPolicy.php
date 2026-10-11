<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

require_once __DIR__ . '/V5NativeTotpStatus.php';

use Throwable;

/** Global Bitrix OTP configuration, NOT a statement about a specific user. */
final readonly class V5GlobalOtpConfig
{
    public function __construct(
        public ?bool $enabled,
        public ?bool $mandatory,
    ) {}
}

interface V5GlobalOtpConfigPort
{
    public function read(): ?V5GlobalOtpConfig;
}

/**
 * Per-user proof that password+SMS is an allowed non-downgrade path.
 * Must check current account/group policy and whether a bypass/mandatory
 * security policy applies. Do not derive true from absent b_sec_user alone.
 */
interface V5PerUserSmsEligibilityPort
{
    public function isSmsAllowedWithoutNativeMfa(int $userId): ?bool;
}

/**
 * Called ONLY if a fresh b_sec_user lookup found NO OTP credential row.
 * Any configured but inactive factor is denied by V5NativeTotpStatus.
 * A GLOBAL non-mandatory setting does not override a per-user restriction.
 */
final readonly class V5StrictNoMfaFallbackPolicy implements V5NoMfaFallbackPolicyPort
{
    public function __construct(
        private V5GlobalOtpConfigPort $global,
        private V5PerUserSmsEligibilityPort $perUser,
    ) {}

    public function permitsPasswordPlusSms(int $userId): ?bool
    {
        if ($userId < 1) {
            return null;
        }
        $setting = $this->global->read();
        if ($setting === null || !is_bool($setting->enabled) || !is_bool($setting->mandatory)) {
            return null;
        }
        if ($setting->mandatory) {
            return false;
        }
        // When the global native OTP feature is switched OFF, an explicitly
        // unconfigured user may still complete a real, rate-limited SMS MFA.
        // An existing OTP record is never downgraded through this interface.
        return $this->perUser->isSmsAllowedWithoutNativeMfa($userId);
    }
}

/**
 * Staging-only read of previously inspected native Bitrix OTP options.
 * Does not read a TOTP secret, verify a code, or open a user session.
 * Runtime availability and option behavior must be verified in staging.
 */
final class V5NativeBitrixGlobalOtpConfig implements V5GlobalOtpConfigPort
{
    public function read(): ?V5GlobalOtpConfig
    {
        $type = \Bitrix\Security\Mfa\Otp::class;
        if (!class_exists($type)
            || !is_callable([$type, 'isOtpEnabled'])
            || !is_callable([$type, 'isMandatoryUsing'])
        ) {
            return null;
        }
        try {
            $enabled = $type::isOtpEnabled();
            $mandatory = $type::isMandatoryUsing();
        } catch (Throwable) {
            return null;
        }
        if (!is_bool($enabled) || !is_bool($mandatory)) {
            return null;
        }
        return new V5GlobalOtpConfig($enabled, $mandatory);
    }
}
