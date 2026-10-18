<?php
declare(strict_types=1);

namespace Evrasia\Samzaberu\V5;

/**
 * Pure SMS dispatch limiter. The authoritative adapter must persist counters
 * atomically with row locks/unique keys, keyed by account and login attempt.
 * Unknown/missing throttle state always DENIES. No SMS is dispatched here.
 */
final class V5SmsDispatchPolicy
{
    public static function allows(
        ?int $lastSentAt,
        ?int $accountMessagesLastHour,
        ?int $attemptMessages,
        int $now,
    ): bool {
        if ($now < 1 || $accountMessagesLastHour === null
            || $attemptMessages === null
            || $accountMessagesLastHour < 0 || $attemptMessages < 0
            || $accountMessagesLastHour >= 3 || $attemptMessages >= 2) {
            return false;
        }
        return $lastSentAt === null
            || ($lastSentAt >= 1 && $now >= $lastSentAt
                && $now - $lastSentAt >= 60);
    }
}
