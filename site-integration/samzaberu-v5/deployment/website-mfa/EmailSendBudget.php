<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Separate no-SMS email verification budget. Feature-only.
 * Apply to BOTH initial email and future explicit resend endpoints.
 * Use the SAME existing, source-verified loopback Redis connection on site.
 *
 * Deliberately does not depend on IP attribution (currently unverified).
 * Owner decision 2026-10-25: NO global blocking email cap until usage
 * statistics are reviewed. The global Redis hourly key is telemetry ONLY
 * (successful budget reservations, not proven mail deliveries).
 * Preserve existing per-user cooldown and hourly anti-spam protections.
 */
final class EmailSendBudget
{
    public const PER_USER_COOLDOWN_SECONDS = 60;
    public const PER_USER_PER_HOUR = 5;


    private const LUA = <<<'LUA'
local user_hourly_limit = tonumber(ARGV[1])
local cooldown_seconds = tonumber(ARGV[2])
local hourly_ttl = tonumber(ARGV[3])
if not user_hourly_limit or user_hourly_limit < 1
    or not cooldown_seconds or cooldown_seconds < 1
    or not hourly_ttl or hourly_ttl < 1 then
    return 0
end
-- The 60-second cooldown is rolling, not a fixed minute bucket.
if redis.call('GET', KEYS[1]) then
    return 0
end
local used = tonumber(redis.call('GET', KEYS[2]) or '0')
if not used or used >= user_hourly_limit then
    return 0
end
redis.call('SET', KEYS[1], '1', 'EX', cooldown_seconds)
local user_count = redis.call('INCR', KEYS[2])
if user_count == 1 then
    redis.call('EXPIRE', KEYS[2], hourly_ttl)
end
-- Non-blocking observation only. Never GET/compare a global quota.
-- A damaged telemetry key must not deny an otherwise permitted login.
local aggregate = redis.pcall('INCR', KEYS[3])
if type(aggregate) == 'number' and aggregate == 1 then
    redis.pcall('EXPIRE', KEYS[3], hourly_ttl)
end
return 1
LUA;

    public static function reserve(object $redis, int $userId, ?int $now = null): bool
    {
        $time = $now ?? time();
        if ($userId <= 0 || $time <= 0 || !method_exists($redis, 'eval')) {
            return false;
        }

        // No raw address, phone number, user ID, PHP session ID or IP in keys.
        $accountHash = hash('sha256', (string) $userId);
        $hour = intdiv($time, 3600);
        $prefix = 'evrasia:{web_mfa_email_login}:v1:';
        $keys = [
            $prefix . 'user:cooldown:' . $accountHash,
            $prefix . 'user:hour:' . $hour . ':' . $accountHash,
            $prefix . 'global:hour:' . $hour,
        ];

        try {
            $result = $redis->eval(self::LUA, [
                ...$keys,
                self::PER_USER_PER_HOUR,
                self::PER_USER_COOLDOWN_SECONDS,
                7200,
            ], 3);
            return $result === 1 || $result === '1';
        } catch (\Throwable) {
            return false;
        }
    }
}
