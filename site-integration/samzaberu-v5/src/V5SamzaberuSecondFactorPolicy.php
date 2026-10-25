<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Pure V5 authentication decision policy; NO device cookie, phone number,
 * client supplied MFA flags, SMS gateway, Bitrix or session state is trusted.
 *
 * Inputs are independent server-verified facts. Unknown facts DENY.
 * This is NOT an API route and does not send an SMS or grant a session.
 */
final class V5SamzaberuSecondFactorPolicy
{
    public const DENY = 'deny';
    public const REQUIRE_TOTP = 'require_totp';
    public const REQUIRE_SMS = 'require_sms'; // explicit recovery only
    public const REQUIRE_EMAIL = 'require_email';
    public const VERIFIED_TRUST = 'verified_trust';

    public static function decide(
        bool $passwordVerified,
        ?bool $totpEnabledServerSide,
        ?bool $trustedDeviceVerifiedServerSide,
        bool $accountActive,
        bool $accountBlocked,
    ): string {
        if (!$passwordVerified || !$accountActive || $accountBlocked
            || $totpEnabledServerSide === null) {
            return self::DENY;
        }
        // Trust is a SERVER-verified strong enrollment, not a cookie-only
        // assertion. Unknown trust status is rejected, never used as proof.
        if ($trustedDeviceVerifiedServerSide === true) {
            return self::VERIFIED_TRUST;
        }
        if ($totpEnabledServerSide) {
            return self::REQUIRE_TOTP;
        }
        if ($trustedDeviceVerifiedServerSide === null) {
            return self::DENY;
        }
        // Normal second factor for accounts without TOTP is email OTP.
        // SMS is available only by a separately verified recovery action.
        return self::REQUIRE_EMAIL;
    }

    /**
     * A login is never authenticated merely because a challenge was issued.
     * An MFA proof must be server-verified and bound to this login attempt.
     */
    public static function permitsSession(
        string $decision,
        bool $totpProofVerifiedForAttempt,
        bool $smsProofVerifiedForAttempt,
        bool $emailProofVerifiedForAttempt = false,
    ): bool {
        return match ($decision) {
            self::REQUIRE_TOTP => $totpProofVerifiedForAttempt,
            self::REQUIRE_SMS => $smsProofVerifiedForAttempt,
            self::REQUIRE_EMAIL => $emailProofVerifiedForAttempt,
            self::VERIFIED_TRUST => true,
            default => false,
        };
    }
}
