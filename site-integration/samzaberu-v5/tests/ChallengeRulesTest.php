<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/ChallengeRules.php';

use Evrasia\Samzaberu\V5\ChallengeRules;

$now = 1781200000;
$userId = 42;
$binding = str_repeat('a', 64);
$base = [
    'status' => 'pending',
    'password_proven' => true,
    'kind' => 'sms',
    'user_id' => $userId,
    'device_binding' => $binding,
    'issued_at' => $now - 100,
    'expires_at' => $now + 150,
    'failed_attempts' => 0,
    'last_sms_sent_at' => $now - 61,
    'sms_sent_count' => 1,
];

$pass = 0;
$verify = static function (
    string $name,
    array $record,
    bool $expected,
    int $user = 42,
    string $device = '',
    string $kind = 'sms',
    int $at = 1781200000
) use ($binding, &$pass): void {
    $actual = ChallengeRules::canVerify(
        $record,
        $user,
        $device !== '' ? $device : $binding,
        $kind,
        $at
    );
    if ($actual !== $expected) {
        throw new RuntimeException('FAIL canVerify: ' . $name);
    }
    $pass++;
};
$resend = static function (
    string $name,
    array $record,
    bool $budgetClear,
    bool $expected
) use ($userId, $binding, $now, &$pass): void {
    $actual = ChallengeRules::canResendSms(
        $record, $userId, $binding, $now, $budgetClear
    );
    if ($actual !== $expected) {
        throw new RuntimeException('FAIL canResendSms: ' . $name);
    }
    $pass++;
};

$verify('valid sms', $base, true);
$totp = $base;
$totp['kind'] = 'totp';
$verify('valid totp', $totp, true, kind: 'totp');

foreach ([
    ['status', 'consumed'],
    ['status', 'failed'],
    ['password_proven', false],
    ['password_proven', 'true'],
    ['kind', 'totp'],
    ['kind', 'other'],
    ['user_id', 43],
    ['user_id', '42'],
    ['device_binding', str_repeat('b', 64)],
    ['device_binding', 'sz_not_a_digest'],
    ['issued_at', $now + 1],
    ['issued_at', 0],
    ['issued_at', 'yesterday'],
    ['expires_at', $now],
    ['expires_at', $now - 1],
    ['expires_at', $now + 301],
    ['expires_at', 'tomorrow'],
    ['failed_attempts', 5],
    ['failed_attempts', 6],
    ['failed_attempts', -1],
    ['failed_attempts', '0'],
] as [$field, $value]) {
    $r = $base;
    $r[$field] = $value;
    $verify('bad ' . $field . '=' . (is_scalar($value) ? (string) $value : gettype($value)), $r, false);
}
foreach ([
    'status', 'password_proven', 'kind', 'user_id',
    'device_binding', 'issued_at', 'expires_at', 'failed_attempts'
] as $field) {
    $r = $base;
    unset($r[$field]);
    $verify('missing ' . $field, $r, false);
}
$verify('wrong user parameter', $base, false, user: 43);
$verify('invalid user parameter', $base, false, user: 0);
$verify('wrong device parameter', $base, false, device: str_repeat('b', 64));
$verify('invalid device parameter', $base, false, device: 'not_hex');
$verify('wrong required factor', $base, false, kind: 'totp');
$verify('unknown required factor', $base, false, kind: 'none');
$verify('invalid current time', $base, false, at: 0);
$verify('expired at verification time', $base, false, at: $now + 150);

$resend('valid resend', $base, true, true);
$resend('blocked global budget', $base, false, false);
$resend('totp cannot resend', $totp, true, false);
foreach ([
    ['last_sms_sent_at', $now - 59],
    ['last_sms_sent_at', $now + 1],
    ['last_sms_sent_at', $now - 101],
    ['last_sms_sent_at', 'bad'],
    ['sms_sent_count', 0],
    ['sms_sent_count', 3],
    ['sms_sent_count', 10],
    ['sms_sent_count', '1'],
    ['failed_attempts', 5],
    ['status', 'consumed'],
    ['expires_at', $now],
] as [$field, $value]) {
    $r = $base;
    $r[$field] = $value;
    $resend('invalid resend ' . $field, $r, true, false);
}
foreach (['last_sms_sent_at', 'sms_sent_count'] as $field) {
    $r = $base;
    unset($r[$field]);
    $resend('missing resend ' . $field, $r, true, false);
}

echo "PASS: V5 challenge rules ($pass cases)\n";
echo "CHALLENGE_STORE_IMPLEMENTED=NO\n";
echo "SMS_GATEWAY_CALLED=NO\n";
echo "NATIVE_OTP_VERIFIED=NO\n";
echo "PRODUCTION_MODIFIED=NO\n";
