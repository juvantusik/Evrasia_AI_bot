<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Verified-email status for BOTH account UI and email-first login eligibility.
 * Pure server-side policy: no DB, login, mail or SMS changes.
 *
 * IMPORTANT: a filled b_user.EMAIL, ACTIVE=Y or empty CONFIRM_CODE does NOT
 * prove ownership of the CURRENT email. Only an explicit, durable proof of a
 * successfully entered email challenge for the same current address qualifies.
 *
 * The future DB adapter MUST read its own ownership-proof record and pass it
 * here. This code cannot be used to "bootstrap" confirmation for old emails.
 */
final class VerifiedEmailStatus
{
    public const SOURCE_OTP = 'email_ownership_otp';

    /**
     * @param array<string,mixed>|null $proof Trusted, DB-backed ownership record
     *   user_id, current_email_mac, verified_at, email_changed_at, source, revoked
     * @return array{verified:bool,login_email_allowed:bool,icon:string,label:string,css_class:string}
     */
    public static function evaluate(
        int $userId,
        string $currentEmail,
        ?array $proof,
        string $siteSecret,
        ?int $now = null
    ): array {
        $unverified = [
            'verified' => false,
            'login_email_allowed' => false,
            'icon' => 'x-circle',
            'label' => 'Email не подтверждён',
            'css_class' => 'email-unverified',
        ];

        $canonical = strtolower(trim($currentEmail));
        $clock = $now ?? time();
        if ($userId <= 0
            || $canonical === ''
            || strlen($canonical) > 254
            || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false
            || strlen($siteSecret) < 32
            || $clock <= 0
            || !is_array($proof)) {
            return $unverified;
        }

        if (($proof['user_id'] ?? null) !== $userId
            || ($proof['source'] ?? null) !== self::SOURCE_OTP
            || ($proof['revoked'] ?? null) !== false
            || !is_int($proof['verified_at'] ?? null)
            || !is_int($proof['email_changed_at'] ?? null)
            || $proof['verified_at'] <= 0
            || $proof['verified_at'] > $clock
            || $proof['email_changed_at'] > $proof['verified_at']
            || !is_string($proof['current_email_mac'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $proof['current_email_mac']) !== 1) {
            return $unverified;
        }

        $payload = 'evrasia-verified-current-email:v1:'
            . $userId . ':' . $canonical;
        $expected = hash_hmac('sha256', $payload, $siteSecret);

        if (!hash_equals($expected, $proof['current_email_mac'])) {
            return $unverified;
        }

        return [
            'verified' => true,
            'login_email_allowed' => true,
            'icon' => 'check-circle',
            'label' => 'Email подтверждён',
            'css_class' => 'email-verified',
        ];
    }

    public static function emailMac(
        int $userId,
        string $currentEmail,
        string $siteSecret
    ): ?string {
        $canonical = strtolower(trim($currentEmail));
        if ($userId <= 0
            || strlen($siteSecret) < 32
            || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false
            || strlen($canonical) > 254) {
            return null;
        }
        return hash_hmac(
            'sha256',
            'evrasia-verified-current-email:v1:'
                . $userId . ':' . $canonical,
            $siteSecret
        );
    }
}
