<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SmsLoginChallenge.php';
use Evrasia\Samzaberu\V5\V5SmsLoginChallenge as C;

$attempt = str_repeat('a', 32);
$other = str_repeat('b', 32);
$secret = str_repeat('K', 48);
$now = 1800000000;
$assert = static function (bool $b, string $s): void {
    if (!$b) throw new RuntimeException('FAIL: '.$s);
};
$challenge = C::issue($attempt, 123, '037492', $secret, $now);
$assert(!str_contains($challenge->codeMac, '037492'), 'code leaked');
$assert($challenge->expiresAt === $now + 300, 'TTL');
$wrong = $challenge->verify($attempt, 123, '111111', $secret, $now + 1);
$assert(!$wrong['accepted'] && $wrong['challenge']->failedAttempts === 1, 'wrong code');
$assert(!$challenge->verify($other, 123, '037492', $secret, $now + 1)['accepted'], 'attempt mismatch');
$assert(!$challenge->verify($attempt, 124, '037492', $secret, $now + 1)['accepted'], 'user mismatch');
$assert(!$challenge->verify($attempt, 123, '037492', $secret, $now + 300)['accepted'], 'expiry');
$assert(!$challenge->verify($attempt, 123, '037492', str_repeat('X',48), $now + 1)['accepted'], 'secret mismatch');
$good = $challenge->verify($attempt, 123, '037492', $secret, $now + 2);
$assert($good['accepted'] && $good['challenge']->consumed, 'correct');
$assert(!$good['challenge']->verify($attempt, 123, '037492', $secret, $now + 3)['accepted'], 'replay');
$state = $challenge;
for ($i=0; $i<5; $i++) $state = $state->verify($attempt, 123, '000000', $secret, $now + 1)['challenge'];
$assert($state->failedAttempts === 5, 'failure count');
$assert(!$state->verify($attempt, 123, '037492', $secret, $now + 2)['accepted'], 'locked challenge');
try {
    C::issue($attempt, 123, '12345', $secret, $now);
    throw new RuntimeException('FAIL: wrong length admitted');
} catch (RuntimeException $e) {
    $assert($e->getMessage()==='v5_sms_challenge_invalid_server_input','format rejection');
}
echo "PASS: 12 SMS challenge state-machine assertions\n";
echo "SMS_SENT=NO\nPHONE_INPUT=NO\nDATABASE_WRITE=NO\n";
