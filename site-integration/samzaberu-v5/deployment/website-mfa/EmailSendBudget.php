<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Separate no-SMS email verification budget. Feature-only.
 * Apply to BOTH initial email and future explicit resend endpoints.
 * Use the SAME existing, source-verified loopback Redis connection on site.
 *
 * Deliberately does not depend on IP attribution (currently unverified).
 * Limits are a proposed starting point; they require review before install.
 */
final class EmailSendBudget
{
    public const PER_USER_COOLDOWN_SECONDS = 60;
    public const PER_USER_PER_HOUR = 5;
    public const GLOBAL_PER_HOUR = 1000;

    private const LUA = <<<'LUA'
local limits = {
    tonumber(ARGV[1]),
    tonumber(ARGV[2]),
    tonumber(ARGV[3])
}
for i = 1, 3 do
    local n = tonumber(redis.call('GET', KEYS[i]) or '0')
    if not n or n >= limits[i] then
        return 0
    end
end
for i = 1, 3 do
    local n = redis.call('INCR', KEYS[i])
    if n == 1 then
        redis.call('EXPIRE', KEYS[i], tonumber(ARGV[i + 3]))
    end
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
        $minute = intdiv($time, self::PER_USER_COOLDOWN_SECONDS);
        $hour = intdiv($time, 3600);
        $prefix = 'evrasia:{web_mfa_email_login}:v1:';
        $keys = [
            $prefix . 'user:minute:' . $minute . ':' . $accountHash,
            $prefix . 'user:hour:' . $hour . ':' . $accountHash,
            $prefix . 'global:hour:' . $hour,
        ];

        try {
            $result = $redis->eval(self::LUA, [
                ...$keys,
                1,
                self::PER_USER_PER_HOUR,
                self::GLOBAL_PER_HOUR,
                120,
                7200,
                7200,
            ], 3);
            return $result === 1 || $result === '1';
        } catch (\Throwable) {
            return false;
        }
    }
}
