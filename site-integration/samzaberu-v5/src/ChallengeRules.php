<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Pure, fail-closed rules for a pending V5 password-authentication challenge.
 *
 * Input $record MUST be fetched from an authoritative server-side store.
 * $deviceBinding MUST be computed by the server from the validated installation
 * identifier. The raw client request is NOT an acceptable $record.
 *
 * This class does not verify TOTP/SMS codes or perform atomic state changes.
 */
final class ChallengeRules
{
    public const MAX_TTL_SECONDS = 300;
    public const MAX_FAILED_ATTEMPTS = 5;
    public const SMS_RESEND_COOLDOWN_SECONDS = 60;
    public const MAX_SMS_SENDS_PER_CHALLENGE = 3;

    public static function canVerify(
        array $record,
        int $userId,
        string $deviceBinding,
        string $requiredKind,
        int $now
    ): bool {
        if ($now <= 0 || $userId <= 0
            || !in_array($requiredKind, ['totp', 'sms'], true)
            || preg_match('/\A[a-f0-9]{64}\z/D', $deviceBinding) !== 1
        ) {
            return false;
        }

        if (($record['status'] ?? null) !== 'pending'
            || ($record['password_proven'] ?? null) !== true
            || ($record['kind'] ?? null) !== $requiredKind
            || !is_int($record['user_id'] ?? null)
            || $record['user_id'] !== $userId
            || !is_string($record['device_binding'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $record['device_binding']) !== 1
            || !hash_equals($record['device_binding'], $deviceBinding)
        ) {
            return false;
        }

        $issuedAt = $record['issued_at'] ?? null;
        $expiresAt = $record['expires_at'] ?? null;
        $failedAttempts = $record['failed_attempts'] ?? null;

        if (!is_int($issuedAt) || !is_int($expiresAt)
            || $issuedAt <= 0 || $issuedAt > $now
            || $expiresAt <= $now || $expiresAt <= $issuedAt
            || ($expiresAt - $issuedAt) > self::MAX_TTL_SECONDS
            || !is_int($failedAttempts)
            || $failedAttempts < 0
            || $failedAttempts >= self::MAX_FAILED_ATTEMPTS
        ) {
            return false;
        }

        return true;
    }

    public static function canResendSms(
        array $record,
        int $userId,
        string $deviceBinding,
        int $now,
        bool $globalSmsBudgetsVerifiedAndClear
    ): bool {
        if (!$globalSmsBudgetsVerifiedAndClear
            || !self::canVerify($record, $userId, $deviceBinding, 'sms', $now)
        ) {
            return false;
        }

        $lastSentAt = $record['last_sms_sent_at'] ?? null;
        $sentCount = $record['sms_sent_count'] ?? null;

        if (!is_int($lastSentAt)
            || !is_int($sentCount)
            || $sentCount < 1
            || $sentCount >= self::MAX_SMS_SENDS_PER_CHALLENGE
            || $lastSentAt < $record['issued_at']
            || $lastSentAt > $now
            || ($now - $lastSentAt) < self::SMS_RESEND_COOLDOWN_SECONDS
        ) {
            return false;
        }

        return true;
    }
}
