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
    public const REQUIRE_SMS = 'require_sms';
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
        // TOTP takes precedence over any trusted-device cookie or SMS path.
        if ($totpEnabledServerSide) {
            return self::REQUIRE_TOTP;
        }
        if ($trustedDeviceVerifiedServerSide === null) {
            return self::DENY;
        }
        return $trustedDeviceVerifiedServerSide
            ? self::VERIFIED_TRUST : self::REQUIRE_SMS;
    }

    /**
     * A login is never authenticated merely because a challenge was issued.
     * An MFA proof must be server-verified and bound to this login attempt.
     */
    public static function permitsSession(
        string $decision,
        bool $totpProofVerifiedForAttempt,
        bool $smsProofVerifiedForAttempt,
    ): bool {
        return match ($decision) {
            self::REQUIRE_TOTP => $totpProofVerifiedForAttempt,
            self::REQUIRE_SMS => $smsProofVerifiedForAttempt,
            self::VERIFIED_TRUST => true,
            default => false,
        };
    }
}
