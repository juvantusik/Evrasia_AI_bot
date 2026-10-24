<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Website-only email challenge for an existing password-verified login.
 *
 * This is a pure state machine. Its caller MUST:
 *   - verify the password against native Bitrix and bind the same USER_ID;
 *   - check the server-side trusted device and native active TOTP first;
 *   - prove that the CURRENT profile email has previously been confirmed;
 *   - apply CSRF, session, account and email-send rate limiting;
 *   - inject a server-owned mail sender (never return the code via HTTP);
 *   - grant web authorization/Trusted Device/PIN ONLY after proof passes.
 *
 * No Bitrix, DB, Redis, SMTP or SMS operation occurs in this class.
 * Not connected to production until the live signin/UI contracts are pinned.
 */
final class EmailLoginChallenge
{
    public const TTL_SECONDS = 300;
    public const MAX_ATTEMPTS = 3;

    private const SESSION_KEY = 'evrasia_web_email_challenge_v1';

    private static function normalizedEmail(string $email): ?string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }
        return $email;
    }

    private static function contextId(string $sessionId): ?string
    {
        if ($sessionId === '' || strlen($sessionId) > 256) {
            return null;
        }
        return hash('sha256', $sessionId);
    }

    private static function isContext(
        array $state,
        int $userId,
        string $email,
        string $sessionId,
        string $challengeId,
        int $now
    ): bool {
        $canonical = self::normalizedEmail($email);
        $sessionHash = self::contextId($sessionId);
        if ($userId <= 0 || $canonical === null || $sessionHash === null
            || preg_match('/^[a-f0-9]{32}$/', $challengeId) !== 1
            || ($state['user_id'] ?? 0) !== $userId
            || !is_string($state['challenge_id'] ?? null)
            || !hash_equals($state['challenge_id'], $challengeId)
            || !is_string($state['session_hash'] ?? null)
            || !hash_equals($state['session_hash'], $sessionHash)
            || !is_string($state['email_hash'] ?? null)
            || !hash_equals($state['email_hash'], hash('sha256', $canonical))
            || !is_int($state['expires_at'] ?? null)
            || $now <= 0 || $now >= $state['expires_at']
            || !is_int($state['attempts'] ?? null)
            || $state['attempts'] >= self::MAX_ATTEMPTS
            || !is_string($state['code_hash'] ?? null)) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string,mixed> $serverSession current locked server-side PHP session
     * @param callable(int):bool $reserveEmailSend fail-closed per-user/global budget
     * @param callable(string,string):bool $sendEmail securely send code via site transport
     * @return array{status:string,challenge_id?:string,expires_at?:int}
     */
    public static function begin(
        array &$serverSession,
        int $userId,
        string $currentEmail,
        bool $confirmedEmail,
        bool $passwordVerified,
        bool $nativeTotpActivated,
        bool $validExistingTrust,
        string $sessionId,
        callable $reserveEmailSend,
        callable $sendEmail,
        ?int $now = null
    ): array {
        // Never let an old email proof be reused across a new password attempt.
        unset($serverSession[self::SESSION_KEY]);
        $time = $now ?? time();
        $canonical = self::normalizedEmail($currentEmail);
        $contextId = self::contextId($sessionId);

        if (!$passwordVerified || $nativeTotpActivated || $validExistingTrust
            || !$confirmedEmail || $userId <= 0 || $time <= 0
            || $canonical === null || $contextId === null) {
            return ['status' => 'sms_or_native_factor_required'];
        }

        try {
            if ($reserveEmailSend($userId) !== true) {
                return ['status' => 'email_unavailable'];
            }

            $code = (string) random_int(100000, 999999);
            $challengeId = bin2hex(random_bytes(16));
            $codeHash = password_hash($code, PASSWORD_DEFAULT);
            if (!is_string($codeHash)) {
                return ['status' => 'email_unavailable'];
            }

            $serverSession[self::SESSION_KEY] = [
                'user_id' => $userId,
                'email_hash' => hash('sha256', $canonical),
                'session_hash' => $contextId,
                'challenge_id' => $challengeId,
                'code_hash' => $codeHash,
                'attempts' => 0,
                'expires_at' => $time + self::TTL_SECONDS,
            ];

            // Only the mail adapter sees the raw code. It never goes into
            // the session state, HTTP response, logs, Redis or database.
            if ($sendEmail($canonical, $code) !== true) {
                unset($serverSession[self::SESSION_KEY]);
                return ['status' => 'email_unavailable'];
            }
        } catch (\Throwable) {
            unset($serverSession[self::SESSION_KEY]);
            return ['status' => 'email_unavailable'];
        }

        return [
            'status' => 'email_challenge',
            'challenge_id' => $challengeId,
            'expires_at' => $time + self::TTL_SECONDS,
        ];
    }

    /** Email verification alone NEVER performs CUser::Authorize(). */
    public static function verify(
        array &$serverSession,
        int $userId,
        string $currentEmail,
        bool $confirmedEmail,
        string $sessionId,
        string $challengeId,
        string $submittedCode,
        ?int $now = null
    ): bool {
        $state = $serverSession[self::SESSION_KEY] ?? null;
        if (!is_array($state)) {
            return false;
        }

        $time = $now ?? time();
        if (!$confirmedEmail || !self::isContext(
            $state, $userId, $currentEmail, $sessionId, $challengeId, $time
        )) {
            // Changed email, expired state or revoked confirmation cannot pass.
            unset($serverSession[self::SESSION_KEY]);
            return false;
        }

        $serverSession[self::SESSION_KEY]['attempts']++;
        if (preg_match('/^[0-9]{6}$/D', $submittedCode) !== 1
            || !password_verify($submittedCode, $state['code_hash'])) {
            if ($serverSession[self::SESSION_KEY]['attempts'] >= self::MAX_ATTEMPTS) {
                unset($serverSession[self::SESSION_KEY]);
            }
            return false;
        }

        // One-time consumption before any authorization in the caller.
        unset($serverSession[self::SESSION_KEY]);
        return true;
    }

    /**
     * Explicit user action after "Не приходит код?" => "Получить SMS".
     * The caller must revalidate the stored first-factor proof, phone
     * ownership and current TOTP status before issuing the existing SMS.
     */
    public static function switchToSms(
        array &$serverSession,
        int $userId,
        string $currentEmail,
        string $sessionId,
        string $challengeId,
        ?int $now = null
    ): bool {
        $state = $serverSession[self::SESSION_KEY] ?? null;
        if (!is_array($state)) {
            return false;
        }
        $valid = self::isContext(
            $state,
            $userId,
            $currentEmail,
            $sessionId,
            $challengeId,
            $now ?? time()
        );
        // Any SMS transition voids the email code, including a rejected request.
        unset($serverSession[self::SESSION_KEY]);
        return $valid;
    }

    public static function clear(array &$serverSession): void
    {
        unset($serverSession[self::SESSION_KEY]);
    }
}
