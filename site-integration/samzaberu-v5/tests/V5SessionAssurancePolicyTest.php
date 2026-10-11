<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SessionAssurancePolicy.php';

use Evrasia\Samzaberu\V5\V5SessionAssurancePolicy;

$now = 1781200000;
$claims = [
    'iss' => 'evrasia-samzaberu-v5',
    'aud' => 'samzaberu-v5',
    'token_use' => 'access',
    'version' => 'v5',
    'sub' => '880339',
    'sid' => str_repeat('a', 64),
    'iat' => $now - 20,
    'exp' => $now + 100,
    'auth_epoch' => 4,
];
$session = [
    'version' => 'v5',
    'status' => 'active',
    'revocation_verified' => true,
    'sid' => str_repeat('a', 64),
    'user_id' => 880339,
    'auth_epoch' => 4,
    'assurance' => 'sms',
    'assured_at' => $now - 50,
    'expires_at' => $now + 300,
];

$pass = 0;
$assert = static function (string $name, array $claimsCase, ?array $sessionCase, bool $expected) use ($now, &$pass): void {
    $actual = V5SessionAssurancePolicy::permitsProtectedAction($claimsCase, $sessionCase, $now);
    if ($actual !== $expected) {
        throw new RuntimeException('FAIL: ' . $name);
    }
    $pass++;
};

$assert('verified', $claims, $session, true);
foreach (['totp', 'sms', 'trusted'] as $assurance) {
    $s = $session;
    $s['assurance'] = $assurance;
    $assert('verified ' . $assurance, $claims, $s, true);
}
$assert('missing session', $claims, null, false);
foreach (['iss', 'aud', 'token_use', 'version', 'sub', 'sid', 'iat', 'exp', 'auth_epoch'] as $key) {
    $c = $claims;
    unset($c[$key]);
    $assert('missing claim ' . $key, $c, $session, false);
}
foreach (['version', 'status', 'revocation_verified', 'sid', 'user_id', 'auth_epoch', 'assurance', 'assured_at', 'expires_at'] as $key) {
    $s = $session;
    unset($s[$key]);
    $assert('missing server field ' . $key, $claims, $s, false);
}
foreach ([
    ['iss', 'evrasia-v4'],
    ['aud', 'samzaberu-v4'],
    ['token_use', 'refresh'],
    ['version', 'v4'],
    ['sub', '880340'],
    ['sub', 880339],
    ['sub', '0880339'],
    ['sid', str_repeat('b', 64)],
    ['sid', 'device-id-only'],
    ['iat', $now + 120],
    ['exp', $now],
    ['auth_epoch', 5],
] as [$key, $value]) {
    $c = $claims;
    $c[$key] = $value;
    $assert('invalid claim ' . $key, $c, $session, false);
}
foreach ([
    ['version', 'v4'],
    ['status', 'revoked'],
    ['revocation_verified', false],
    ['revocation_verified', null],
    ['sid', str_repeat('b', 64)],
    ['user_id', 880340],
    ['auth_epoch', 5],
    ['assurance', 'password'],
    ['assurance', ''],
    ['assured_at', $now + 1],
    ['assured_at', $now - 1],
    ['expires_at', $now],
] as [$key, $value]) {
    $s = $session;
    $s[$key] = $value;
    if ($key === 'assured_at' && $value === $now - 1) {
        $assert('assurance after issuance', $claims, $s, false);
    } else {
        $assert('invalid server field ' . $key, $claims, $s, false);
    }
}
$expiredSession = $session;
$expiredSession['expires_at'] = $now - 1;
$assert('expired server session', $claims, $expiredSession, false);
$futureSignature = $claims;
$futureSignature['iat'] = $now + 31;
$assert('iat future', $futureSignature, $session, false);
$negativeNow = V5SessionAssurancePolicy::permitsProtectedAction($claims, $session, 0);
if ($negativeNow !== false) {
    throw new RuntimeException('FAIL: invalid now');
}
$pass++;
echo 'PASS: V5 session assurance policy ' . $pass . " cases\n";
echo "JWT_CRYPTO_NOT_IMPLEMENTED=YES\nSERVER_ASSURANCE_STORE_NOT_IMPLEMENTED=YES\nPRODUCTION_NOT_MODIFIED=YES\n";
