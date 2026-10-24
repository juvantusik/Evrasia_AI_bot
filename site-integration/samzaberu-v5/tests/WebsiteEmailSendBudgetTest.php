<?php
declare(strict_types=1);
require_once __DIR__ . '/../deployment/website-mfa/EmailSendBudget.php';

use Evrasia\WebMfa\EmailSendBudget;

$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException('assertion_failed_' . $label);
    }
    $checks++;
};

final class FakeEmailRedis
{
    public array $calls = [];
    public mixed $value = 1;
    public bool $throws = false;

    public function eval(string $script, array $arguments, int $keyCount): mixed
    {
        if ($this->throws) {
            throw new RuntimeException('private-redis-error-do-not-print');
        }
        $this->calls[] = [$script, $arguments, $keyCount];
        return $this->value;
    }
}
try {
    $redis = new FakeEmailRedis();
    $check(EmailSendBudget::reserve($redis, 42, 1800000000),
        'valid_email_budget_reservation');
    $check(count($redis->calls) === 1, 'single_atomic_call');
    [$lua, $args, $keyCount] = $redis->calls[0];
    $check($keyCount === 3 && count($args) === 9, 'three_keys_six_args');
    $check(str_contains($lua, "redis.call('INCR', KEYS[i])")
        && str_contains($lua, "redis.call('EXPIRE', KEYS[i]"),
        'atomic_lua_with_ttl');
    $check(str_contains($lua, "redis.call('GET', KEYS[i])")
        && str_contains($lua, 'return 0'), 'precheck_all_quotas');
    $check($args[3] === 1 && $args[4] === 5 && $args[5] === 1000,
        'provisional_limits');
    $check($args[6] === 120 && $args[7] === 7200 && $args[8] === 7200,
        'quota_key_ttl');
    $check(!str_contains(json_encode($args, JSON_THROW_ON_ERROR), 'secret@example.com'),
        'no_plaintext_email');
    $check(!str_contains(implode('|', array_slice($args, 0, 3)), '42'),
        'hashed_user_id_not_raw');
    $check(str_starts_with($args[0], 'evrasia:{web_mfa_email_login}:v1:'),
        'isolated_email_prefix');
    $check(!str_contains(implode('|', array_slice($args, 0, 3)), 'paid_sms'),
        'not_using_paid_sms_namespace');

    $redis->value = '1';
    $check(EmailSendBudget::reserve($redis, 42, 1800000000),
        'phpredis_string_success');
    $redis->value = 0;
    $check(!EmailSendBudget::reserve($redis, 42, 1800000000),
        'quota_exhaustion_fails_closed');
    $redis->value = false;
    $check(!EmailSendBudget::reserve($redis, 42, 1800000000),
        'redis_false_fails_closed');
    $redis->throws = true;
    $check(!EmailSendBudget::reserve($redis, 42, 1800000000),
        'redis_error_fails_closed');
    $count = count($redis->calls);
    $check(!EmailSendBudget::reserve($redis, -1, 1800000000),
        'invalid_user_rejected');
    $check(!EmailSendBudget::reserve($redis, 42, -10),
        'invalid_clock_rejected');
    $check(count($redis->calls) === $count, 'invalid_inputs_do_not_eval');

    $redis->throws = false;
    $redis->value = 1;
    $check(EmailSendBudget::reserve($redis, 43, 1800000000),
        'another_user_isolated');
    $last = $redis->calls[array_key_last($redis->calls)][1];
    $check($last[0] !== $args[0] && $last[1] !== $args[1]
        && $last[2] === $args[2], 'per_user_keys_separate_global_shared');

    $check(EmailSendBudget::PER_USER_COOLDOWN_SECONDS === 60
        && EmailSendBudget::PER_USER_PER_HOUR === 5
        && EmailSendBudget::GLOBAL_PER_HOUR === 1000,
        'limit_constants');

    echo "PASS: email-only budget quota, privacy, Redis errors and isolation ($checks assertions)\n";
    echo "MODE=OFFLINE_UNIT_TEST\nREDIS_CONNECTION=NO\nREDIS_WRITE=NO\n";
    echo "SMTP_SEND=NO\nSMS_SENT=NO\nPRODUCTION_SITE_WRITE=NO\n";
    echo "FINAL_STATUS=PASS\nFINAL_RC=0\n";
} catch (\Throwable $error) {
    echo 'FAIL: ' . $error->getMessage() . "\n";
    echo "FINAL_STATUS=FAIL\nFINAL_RC=1\n";
    exit(1);
}
