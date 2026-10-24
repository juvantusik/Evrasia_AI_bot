<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Email OWNERSHIP proof issuance for the WEBSITE account profile.
 *
 * Completely separate from login OTP: a guest must already have logged in via
 * server-verified SMS, native TOTP, or current Trusted Device and have RECENT
 * server assurance; otherwise never enroll a new second-factor email.
 *
 * This pure service never bootstraps Bitrix, writes SQL, sends SMS or authorizes.
 * The caller MUST source all assurance values from trusted server state, read
 * the current user's live Bitrix EMAIL and TIMESTAMP_X on BOTH actions, use
 * CSRF-protected POST, session locks, separate EmailSendBudget and a Bitrix
 * mail template adapter. Only after verify() returns a receipt may the caller
 * persist it in the dedicated website-only proof ledger.
 */
final class EmailOwnershipEnrollment
{
    public const TTL_SECONDS = 300;
    public const MAX_ATTEMPTS = 3;
    public const MAX_ASSURANCE_AGE_SECONDS = 600;
    private const KEY = 'evrasia_web_email_enrollment_v1';

    private static function normalized(string $email): ?string
    {
        $email = strtolower(trim($email));
        return $email !== '' && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            ? $email : null;
    }

    private static function assuranceValid(
        string $kind, int $proofAt, int $now
    ): bool {
        return in_array($kind, ['sms','totp','trusted'], true)
            && $proofAt > 0 && $proofAt <= $now
            && $now - $proofAt <= self::MAX_ASSURANCE_AGE_SECONDS;
    }

    private static function currentProfileValid(
        int $userId, string $email, int $stamp, string $sessionId,
        string $siteSecret, int $now
    ): bool {
        return $userId > 0
            && self::normalized($email) !== null
            && $stamp > 0 && $stamp <= $now
            && $sessionId !== '' && strlen($sessionId) <= 256
            && strlen($siteSecret) >= 32
            && $now > 0;
    }

    /**
     * @param callable(int):bool $reserveSend
     * @param callable(string,string):bool $sendEmail
     * @return array{status:string,challenge_id?:string,expires_at?:int}
     */
    public static function begin(
        array &$session,
        int $userId,
        string $currentEmail,
        int $profileUpdatedAt,
        string $sessionId,
        string $assuranceKind,
        int $assuranceAt,
        string $siteSecret,
        callable $reserveSend,
        callable $sendEmail,
        ?int $now = null
    ): array {
        unset($session[self::KEY]);
        $clock = $now ?? time();
        if (!self::assuranceValid($assuranceKind, $assuranceAt, $clock)
            || !self::currentProfileValid($userId, $currentEmail,
                $profileUpdatedAt, $sessionId, $siteSecret, $clock)) {
            return ['status' => 'recent_verified_login_required'];
        }
        try {
            $canonical = self::normalized($currentEmail);
            $mac = VerifiedEmailStatus::emailMac(
                $userId, $canonical, $siteSecret
            );
            if ($mac === null || $reserveSend($userId) !== true) {
                return ['status' => 'email_unavailable'];
            }
            $code = (string)random_int(100000, 999999);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                return ['status' => 'email_unavailable'];
            }
            $id = bin2hex(random_bytes(16));
            $session[self::KEY] = [
                'user_id' => $userId,
                'email_mac' => $mac,
                'profile_updated_at' => $profileUpdatedAt,
                'session_hash' => hash('sha256', $sessionId),
                'assurance_kind' => $assuranceKind,
                'assurance_at' => $assuranceAt,
                'challenge_id' => $id,
                'code_hash' => $hash,
                'attempts' => 0,
                'expires_at' => $clock + self::TTL_SECONDS,
            ];
            if ($sendEmail($canonical, $code) !== true) {
                unset($session[self::KEY]);
                return ['status' => 'email_unavailable'];
            }
            return [
                'status' => 'email_ownership_challenge',
                'challenge_id' => $id,
                'expires_at' => $clock + self::TTL_SECONDS,
            ];
        } catch (\Throwable) {
            unset($session[self::KEY]);
            return ['status' => 'email_unavailable'];
        }
    }

    /**
     * A receipt does NOT grant login or trust; it only authorizes the exact
     * website-only ownership proof DB write through a source-pinned adapter.
     *
     * @return array{
     *   user_id:int,current_email_mac:string,verified_at:int,
     *   email_changed_at:int,profile_updated_at:int,
     *   source:string,revoked:bool
     * }|null
     */
    public static function verify(
        array &$session,
        int $userId,
        string $currentEmail,
        int $currentProfileUpdatedAt,
        string $sessionId,
        string $assuranceKind,
        int $assuranceAt,
        string $siteSecret,
        string $challengeId,
        string $submittedCode,
        ?int $now = null
    ): ?array {
        $proof = $session[self::KEY] ?? null;
        if (!is_array($proof)) {
            return null;
        }
        $clock = $now ?? time();
        $canonical = self::normalized($currentEmail);
        $emailMac = $canonical === null ? null
            : VerifiedEmailStatus::emailMac(
                $userId, $canonical, $siteSecret
            );
        if (!self::assuranceValid($assuranceKind, $assuranceAt, $clock)
            || !self::currentProfileValid($userId, $currentEmail,
                $currentProfileUpdatedAt, $sessionId, $siteSecret, $clock)
            || ($proof['user_id'] ?? null) !== $userId
            || $emailMac === null
            || !is_string($proof['email_mac'] ?? null)
            || !hash_equals($proof['email_mac'], $emailMac)
            || ($proof['profile_updated_at'] ?? null) !== $currentProfileUpdatedAt
            || ($proof['assurance_kind'] ?? null) !== $assuranceKind
            || ($proof['assurance_at'] ?? null) !== $assuranceAt
            || !is_string($proof['session_hash'] ?? null)
            || !hash_equals($proof['session_hash'], hash('sha256', $sessionId))
            || !is_string($proof['challenge_id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $challengeId) !== 1
            || !hash_equals($proof['challenge_id'], $challengeId)
            || !is_int($proof['expires_at'] ?? null)
            || $clock >= $proof['expires_at']
            || !is_int($proof['attempts'] ?? null)
            || $proof['attempts'] >= self::MAX_ATTEMPTS
            || !is_string($proof['code_hash'] ?? null)) {
            unset($session[self::KEY]);
            return null;
        }

        $session[self::KEY]['attempts']++;
        if (preg_match('/^[0-9]{6}$/D', $submittedCode) !== 1
            || !password_verify($submittedCode, $proof['code_hash'])) {
            if ($session[self::KEY]['attempts'] >= self::MAX_ATTEMPTS) {
                unset($session[self::KEY]);
            }
            return null;
        }

        // Consume the code BEFORE the caller persists the proof.
        unset($session[self::KEY]);
        return [
            'user_id' => $userId,
            'current_email_mac' => $emailMac,
            'verified_at' => $clock,
            'email_changed_at' => $currentProfileUpdatedAt,
            'profile_updated_at' => $currentProfileUpdatedAt,
            'source' => VerifiedEmailStatus::SOURCE_OTP,
            'revoked' => false,
        ];
    }

    public static function clear(array &$session): void
    {
        unset($session[self::KEY]);
    }
}
