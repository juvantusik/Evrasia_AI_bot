<?php
declare(strict_types=1);

require_once __DIR__ . '/../deployment/website-mfa/GlobalSmsBudget.php';

use Evrasia\WebMfa\GlobalSmsBudget;

final class FakeWebSmsRedis
{
    public array $counts = [];
    public int $evals = 0;
    public array $lastKeys = [];
    public array $lastCaps = [];
    public bool $fail = false;

    public function eval(string $lua, array $args, int $keyCount): int
    {
        if ($this->fail) {
            throw new RuntimeException('redis_denied');
        }
        if ($keyCount !== 3 || count($args) !== 9
            || !str_contains($lua, "redis.call('INCR', KEYS[i])")
            || !str_contains($lua, "redis.call('EXPIRE', KEYS[i]")) {
            throw new RuntimeException('invalid_atomic_quota_script');
        }
        ++$this->evals;
        $keys = array_slice($args, 0, 3);
        $caps = array_slice($args, 3, 3);
        $this->lastKeys = $keys;
        $this->lastCaps = $caps;
        for ($i = 0; $i < 3; ++$i) {
            if (($this->counts[$keys[$i]] ?? 0) >= $caps[$i]) {
                return 0;
            }
        }
        foreach ($keys as $key) {
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }
        return 1;
    }
}

$assertions = 0;
function good(bool $ok, string $label): void
{
    global $assertions;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    ++$assertions;
}

good(GlobalSmsBudget::GLOBAL_PER_HOUR === 100
    && GlobalSmsBudget::GLOBAL_PER_DAY === 500
    && GlobalSmsBudget::IP_PER_HOUR === 20,
    'fixed reviewed paid-SMS ceilings');

$r = new FakeWebSmsRedis();
good(!GlobalSmsBudget::reserve($r, 'spoofed-ip', 'login', 1800000000)
    && $r->evals === 0, 'reject untrusted invalid REMOTE_ADDR');
good(!GlobalSmsBudget::reserve($r, '192.0.2.1', 'unknown', 1800000000)
    && $r->evals === 0, 'reject unrecognized budget flow');

$t = 1800000000;
good(GlobalSmsBudget::reserve($r, '192.0.2.1', 'login', $t),
    'first paid login reserved');
$keys = $r->lastKeys;
good(count($keys) === 3
    && $r->lastCaps === [100,500,20]
    && !str_contains(implode('|', $keys), '192.0.2.1')
    && count(array_unique($keys)) === 3,
    '3 atomic keys; no raw IP; correct limits');
good(GlobalSmsBudget::reserve($r, '192.0.2.1', 'enrollment', $t)
    && $r->lastKeys === $keys,
    'enrollment and login share same global and IP budget');
for ($i = 2; $i < 20; ++$i) {
    good(GlobalSmsBudget::reserve($r, '192.0.2.1', 'login', $t),
        'ip quota permits exactly 20');
}
good(!GlobalSmsBudget::reserve($r, '192.0.2.1', 'login', $t)
    && $r->counts[$keys[2]] === 20, 'IP quota denies 21st send');

for ($host = 2; $host <= 5; ++$host) {
    for ($i = 0; $i < 20; ++$i) {
        good(GlobalSmsBudget::reserve($r, '192.0.2.' . $host, 'enrollment', $t),
            'global budget permits first 100 across IPs and flows');
    }
}
good(!GlobalSmsBudget::reserve($r, '192.0.2.6', 'login', $t)
    && $r->counts[$keys[0]] === 100,
    'global hourly cap denies 101st across new IP');

for ($hour = 1; $hour < 5; ++$hour) {
    for ($host = 1; $host <= 5; ++$host) {
        for ($i = 0; $i < 20; ++$i) {
            good(GlobalSmsBudget::reserve(
                $r, '192.0.2.' . $host, 'login', $t + $hour * 3600
            ), 'daily cross-hour budget');
        }
    }
}
good(!GlobalSmsBudget::reserve($r, '192.0.2.11', 'enrollment', $t + 5*3600)
    && array_sum(array_filter($r->counts,
        static fn($k) => str_contains((string)$k, 'global:day:'),
        ARRAY_FILTER_USE_KEY)) === 500,
    'shared daily cap denies 501st');

$ipv6 = new FakeWebSmsRedis();
good(GlobalSmsBudget::reserve($ipv6, '2001:db8::1', 'login', $t),
    'IPv6 normalized to opaque hashed IP key');
$ipv6->fail = true;
good(!GlobalSmsBudget::reserve($ipv6, '2001:db8::1', 'login', $t),
    'Redis error fails closed and does not dispatch SMS');

echo "PASS: website paid-SMS shared global/hour/day/IP synthetic budget "
    . "({$assertions} assertions)\n";
echo "LIVE_REDIS_SCRIPT_EXECUTED=NO\n";
echo "PRODUCTION_SMS_SENT=NO\n";
