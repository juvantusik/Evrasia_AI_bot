<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

enum NextFactor: string
{
    case DENY = 'deny';
    case TOTP = 'totp_challenge';
    case SMS = 'sms_challenge';
    case COMPLETE = 'complete';
}

/**
 * Pure V5 decision policy. No Bitrix bootstrap, DB writes, SMS, cookies or JWT.
 * Every argument must come from server-side verification, never the client.
 */
final class AuthDecision
{
    public static function afterPassword(
        bool $passwordVerified,
        bool $accountAllowed,
        bool $nativeTotpActive,
        bool $trustTokenValid,
        bool $trustStronglyEnrolled,
    ): NextFactor {
        if (!$passwordVerified || !$accountAllowed) {
            return NextFactor::DENY;
        }

        // Native Bitrix MFA has priority even for trusted installations.
        if ($nativeTotpActive) {
            return NextFactor::TOTP;
        }

        // V4 password-only device registrations must never authorize SMS bypass.
        if ($trustTokenValid && $trustStronglyEnrolled) {
            return NextFactor::COMPLETE;
        }

        return NextFactor::SMS;
    }
}
