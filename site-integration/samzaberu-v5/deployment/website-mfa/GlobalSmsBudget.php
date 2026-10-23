<?php
declare(strict_types=1);

namespace Evrasia\WebMfa;

/**
 * Shared paid-SMS quota for website login challenges AND TOTP enrollment.
 *
 * In addition to the existing per-user/phone/cooldown guards, every paid
 * website send must reserve one slot in this Redis budget BEFORE invoking
 * the SMS provider. A failed send still consumes its slot: avoiding an
 * unlimited retry/cost loop is intentional.
 *
 * This file is feature-only. It is NOT installed or called by production.
 * The eventual website integration must call reserve() from BOTH existing
 * send paths and must prove the authentic REMOTE_ADDR reaches PHP. All
 * quotas are conservative initial thresholds, change only with operator
 * agreement and source-pinned deployment.
 */
final class GlobalSmsBudget
{
    public const GLOBAL_PER_HOUR = 100;
    public const GLOBAL_PER_DAY = 500;
    public const IP_PER_HOUR = 20;

    private const LUA = <<<'LUA'
local caps = {
    tonumber(ARGV[1]),
    tonumber(ARGV[2]),
    tonumber(ARGV[3])
}
for i = 1, 3 do
    local n = tonumber(redis.call('GET', KEYS[i]) or '0')
    if n == nil or n >= caps[i] then
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

    /**
     * Reserve one SMS send. No user ID, raw phone, cookie, or raw IP is stored.
     * All three keys use one Redis Cluster hash tag for atomic EVAL.
     *
     * @param object $redis Connected phpredis instance exposing eval().
     * @param string $remoteAddr Trusted server REMOTE_ADDR, not a client header.
     * @param string $purpose Only "login" or "enrollment". Same global quota.
     * @param int|null $now Allows deterministic synthetic test time.
     */
    public static function reserve(
        object $redis,
        string $remoteAddr,
        string $purpose,
        ?int $now = null
    ): bool {
        if (!in_array($purpose, ['login', 'enrollment'], true)
            || !method_exists($redis, 'eval')) {
            return false;
        }

        $ipBytes = @inet_pton($remoteAddr);
        if (!is_string($ipBytes) || $ipBytes === '') {
            return false;
        }

        $time = $now ?? time();
        if ($time <= 0) {
            return false;
        }

        $hour = intdiv($time, 3600);
        $day = intdiv($time, 86400);
        $prefix = 'evrasia:{web_mfa_paid_sms}:v1:';
        $keys = [
            $prefix . 'global:hour:' . $hour,
            $prefix . 'global:day:' . $day,
            $prefix . 'ip:hour:' . $hour . ':' . hash('sha256', $ipBytes),
        ];

        try {
            // phpRedis eval($script, $args, $num_keys).
            // 3 KEYS, then 3 caps and 3 TTL values in ARGV.
            $result = $redis->eval(self::LUA, [
                ...$keys,
                self::GLOBAL_PER_HOUR,
                self::GLOBAL_PER_DAY,
                self::IP_PER_HOUR,
                7200,
                172800,
                7200,
            ], 3);
            return $result === 1 || $result === '1';
        } catch (\Throwable) {
            // Redis outage, ACL error, script error => fail closed:
            // never use an unmetered paid-SMS fallback.
            return false;
        }
    }
}
