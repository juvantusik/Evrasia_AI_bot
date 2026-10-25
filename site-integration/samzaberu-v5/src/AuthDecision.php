<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

enum NextFactor: string
{
    case DENY = 'deny';
    case TOTP = 'totp_challenge';
    case EMAIL = 'email_challenge';
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

        // A CURRENT, server-verified STRONG trust enrollment skips repeated
        // MFA, even when the account has active Bitrix native TOTP. Neither
        // device_id nor an old V4 password-only record is sufficient proof.
        // Issuer MUST lock user+epoch and reverify trust on this very request.
        if ($trustTokenValid && $trustStronglyEnrolled) {
            return NextFactor::COMPLETE;
        }

        // Without current strong trust, a configured native TOTP credential
        // ALWAYS requires TOTP. Never downgrade it to paid SMS.
        if ($nativeTotpActive) {
            return NextFactor::TOTP;
        }

        // Email-first policy confirmed on website. A missing/unverified email
        // transport must DENY, never auto-send paid SMS or return a session.
        // SMS remains an explicit user-requested recovery path only.
        return NextFactor::EMAIL;
    }
}
