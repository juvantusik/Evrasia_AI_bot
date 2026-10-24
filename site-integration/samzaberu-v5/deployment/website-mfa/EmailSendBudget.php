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
-- A fixed minute bucket would allow two emails one second apart when
-- the minute changes. Hold a true per-account 60-second cooldown key.
if redis.call('GET', KEYS[1]) then
    return 0
end
for i = 2, 3 do
    local n = tonumber(redis.call('GET', KEYS[i]) or '0')
    if not n or n >= limits[i] then
        return 0
    end
end
redis.call('SET', KEYS[1], '1', 'EX', tonumber(ARGV[4]))
for i = 2, 3 do
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
                1,
                self::PER_USER_PER_HOUR,
                self::GLOBAL_PER_HOUR,
                self::PER_USER_COOLDOWN_SECONDS,
                7200,
                7200,
            ], 3);
            return $result === 1 || $result === '1';
        } catch (\Throwable) {
            return false;
        }
    }
}
