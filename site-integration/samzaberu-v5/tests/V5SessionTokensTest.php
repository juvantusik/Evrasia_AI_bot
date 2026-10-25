<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5SessionTokens.php';
require_once __DIR__ . '/../src/V5SessionAssurancePolicy.php';

use Evrasia\Samzaberu\V5\V5AccessJwtCodec;
use Evrasia\Samzaberu\V5\V5OpaqueRefreshTokens;
use Evrasia\Samzaberu\V5\V5SessionAssurancePolicy;

$now = 1792000000;
$secret = str_repeat('A', 48); // Only synthetic test keys, never production.
$previousSecret = str_repeat('B', 48);
$codec = new V5AccessJwtCodec(['v5_new' => $secret, 'v5_old' => $previousSecret], 'v5_new', 300);
$session = [
    'version' => 'v5',
    'status' => 'active',
    'revocation_verified' => true,
    'user_id' => 880339,
    'sid' => str_repeat('a', 64),
    'auth_epoch' => 42,
    'assurance' => 'totp',
    'assured_at' => $now - 10,
    'expires_at' => $now + 900,
];
$pass = 0;
$ok = static function (string $name, bool $condition) use (&$pass): void {
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    $pass++;
};
$rejectIssue = static function (string $name, array $s, int $at) use ($codec, $ok): void {
    try {
        $codec->issueForCommittedSession($s, $at);
    } catch (RuntimeException $e) {
        $ok($name, $e->getMessage() === 'v5_session_unavailable');
        return;
    }
    $ok($name, false);
};
$rejectNew = static function (string $name, array $keys, string $kid, int $ttl) use ($ok): void {
    try {
        new V5AccessJwtCodec($keys, $kid, $ttl);
    } catch (InvalidArgumentException $e) {
        $ok($name, true);
        return;
    }
    $ok($name, false);
};
$enc = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$dec = static fn(string $s): string => (string)base64_decode(
    strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true
);
$token = $codec->issueForCommittedSession($session, $now);
$claims = $codec->verifyAccess($token, $now);
$ok('verified real V5 signature', is_array($claims));
$emailSession = array_replace($session, ['assurance' => 'email']);
$emailJwt = $codec->issueForCommittedSession($emailSession, $now);
$emailClaims = $codec->verifyAccess($emailJwt, $now);
$ok('verified email OTP JWT', is_array($emailClaims));
$ok('email OTP server assurance', V5SessionAssurancePolicy::permitsProtectedAction($emailClaims, $emailSession, $now));

$ok('strict issuer', $claims['iss'] === 'evrasia-samzaberu-v5');
$ok('strict audience', $claims['aud'] === 'samzaberu-v5');
$ok('access only', $claims['token_use'] === 'access');
$ok('user binding', $claims['sub'] === '880339');
$ok('session binding', $claims['sid'] === $session['sid']);
$ok('epoch binding', $claims['auth_epoch'] === 42);
$ok('short TTL', $claims['exp'] === $now + 300);
$ok('random JWT ID', preg_match('/\A[a-f0-9]{64}\z/D', $claims['jti']) === 1);
$ok('assurance policy integration', V5SessionAssurancePolicy::permitsProtectedAction($claims, $session, $now));
$ok('replay ID entropy', $codec->issueForCommittedSession($session, $now) !== $token);
$ok('reject at expiry', $codec->verifyAccess($token, $now + 300) === null);
$ok('accept before expiry', $codec->verifyAccess($token, $now + 299) !== null);
$ok('no future-issued token', $codec->verifyAccess($token, $now - 31) === null);
$ok('refuse invalid server clock', $codec->verifyAccess($token, 0) === null);
$ok('refuse empty JWT', $codec->verifyAccess('', $now) === null);
$ok('refuse excessive JWT', $codec->verifyAccess(str_repeat('a', 4097), $now) === null);
$ok('refuse malformed segments', $codec->verifyAccess($token . '.extra', $now) === null);
$ok('refuse padded JWT', $codec->verifyAccess($token . '=', $now) === null);
$ok('refuse mutated JWT', $codec->verifyAccess('Z' . substr($token, 1), $now) === null);

$wrong = new V5AccessJwtCodec(['v5_new' => str_repeat('C', 48)], 'v5_new');
$ok('independent key', $wrong->verifyAccess($token, $now) === null);
$ok('V4 cannot pass', $codec->verifyAccess('v4.jwt.token', $now) === null);
$ok('server revocation gates JWT', !V5SessionAssurancePolicy::permitsProtectedAction(
    $claims, array_replace($session, ['status' => 'revoked']), $now));
$ok('server epoch gates JWT', !V5SessionAssurancePolicy::permitsProtectedAction(
    $claims, array_replace($session, ['auth_epoch' => 43]), $now));
$ok('unknown server session gates JWT', !V5SessionAssurancePolicy::permitsProtectedAction(
    $claims, null, $now));

// Valid signature but malicious/legacy payload/header — reject every downgrade.
$resign = static function (array $header, array $payload, string $key) use ($enc): string {
    $p = $enc(json_encode($header, JSON_THROW_ON_ERROR))
        . '.' . $enc(json_encode($payload, JSON_THROW_ON_ERROR));
    return $p . '.' . $enc(hash_hmac('sha256', $p, $key, true));
};
[$h64, $p64] = explode('.', $token);
$h = json_decode($dec($h64), true, 8, JSON_THROW_ON_ERROR);
$p = json_decode($dec($p64), true, 12, JSON_THROW_ON_ERROR);
foreach ([
    ['iss', 'evrasia-api-v4'], ['aud', 'samzaberu-v4'],
    ['version', 'v4'], ['token_use', 'refresh'],
    ['sub', '0880339'], ['sub', 880339], ['sub', '0'],
    ['sid', str_repeat('B',64)], ['sid', 'device-only'],
    ['auth_epoch', 0], ['auth_epoch', '42'],
    ['iat', $now + 32], ['iat', '1792000000'],
    ['exp', $now], ['exp', $now + 901],
    ['jti', str_repeat('z',64)],
] as [$field, $bad]) {
    $copy = $p;
    $copy[$field] = $bad;
    $ok('signed bad claim ' . $field . (string)$bad,
        $codec->verifyAccess($resign($h, $copy, $secret), $now) === null);
}
foreach ([
    ['alg', 'none'], ['alg', 'HS512'], ['typ', 'not-JWT'],
    ['kid', 'unknown_key'],
] as [$field, $bad]) {
    $copy = $h;
    $copy[$field] = $bad;
    $ok('signed bad header ' . $field,
        $codec->verifyAccess($resign($copy, $p, $secret), $now) === null);
}
$copy = $h; $copy['jku'] = 'https://invalid.example/test.json';
$ok('reject remote key override',
    $codec->verifyAccess($resign($copy, $p, $secret), $now) === null);
$copy = $p; $copy['amr'] = ['password'];
$ok('reject unapproved extra claims',
    $codec->verifyAccess($resign($h, $copy, $secret), $now) === null);
$copy = $p; unset($copy['auth_epoch']);
$ok('reject missing epoch',
    $codec->verifyAccess($resign($h, $copy, $secret), $now) === null);

foreach ([
    ['version', 'v4'], ['status', 'revoked'],
    ['revocation_verified', false], ['revocation_verified', null],
    ['user_id', 0], ['user_id', '880339'],
    ['sid', str_repeat('B', 64)], ['sid', 'x'],
    ['auth_epoch', 0], ['auth_epoch', '42'],
    ['assurance', 'password'], ['assurance', 'unknown'],
    ['assured_at', $now + 1], ['assured_at', null],
    ['expires_at', $now], ['expires_at', null],
] as [$field, $bad]) {
    $rejectIssue('untrusted session ' . $field,
        array_replace($session, [$field => $bad]), $now);
}
$rejectIssue('invalid clock', $session, 0);
$shortSession = array_replace($session, ['expires_at' => $now + 17]);
$shortToken = $codec->issueForCommittedSession($shortSession, $now);
$shortClaims = $codec->verifyAccess($shortToken, $now);
$ok('session expiry caps JWT', $shortClaims['exp'] === $now + 17);
$ok('bounded access TTL', $codec->verifyAccess($shortToken, $now + 17) === null);

$rejectNew('reject short key', ['bad' => str_repeat('k',31)], 'bad', 300);
$rejectNew('reject missing kid', ['valid' => $secret], 'other', 300);
$rejectNew('reject kid URL', ['https://issuer' => $secret], 'https://issuer', 300);
$rejectNew('reject tiny ttl', ['valid' => $secret], 'valid', 29);
$rejectNew('reject huge ttl', ['valid' => $secret], 'valid', 901);
$rejectNew('reject empty keys', [], 'valid', 300);

$rotated = new V5AccessJwtCodec(['v5_new' => $secret, 'v5_old' => $previousSecret], 'v5_old');
$oldToken = $rotated->issueForCommittedSession($session, $now);
$ok('verification supports approved key rotation', $codec->verifyAccess($oldToken, $now) !== null);
$onlyNew = new V5AccessJwtCodec(['v5_new' => $secret], 'v5_new');
$ok('retired key rejected', $onlyNew->verifyAccess($oldToken, $now) === null);

$refresh = new V5OpaqueRefreshTokens(str_repeat('R', 48));
$r1 = $refresh->issue();
$r2 = $refresh->issue();
$ok('refresh bearer shape', preg_match('/\Av5r_[a-f0-9]{64}\z/D', $r1['token']) === 1);
$ok('refresh opaque randomness', !hash_equals($r1['token'], $r2['token']));
$ok('refresh digest matches', hash_equals($r1['digest'], (string)$refresh->digestPresented($r1['token'])));
$ok('refresh contains no bearer in digest', !str_contains($r1['digest'], $r1['token']));
$ok('refresh tokens have different digests', !hash_equals($r1['digest'], $r2['digest']));
$ok('refresh key separation', (new V5OpaqueRefreshTokens(str_repeat('S',48)))
    ->digestPresented($r1['token']) !== $r1['digest']);
foreach (['', 'v4_'.str_repeat('a',64), 'v5r_'.str_repeat('G',64),
    'v5r_'.str_repeat('a',63), $r1['token'].'='] as $bad) {
    $ok('invalid refresh token denied', $refresh->digestPresented($bad) === null);
}
try {
    new V5OpaqueRefreshTokens('weak');
    $ok('reject weak refresh pepper', false);
} catch (InvalidArgumentException $e) {
    $ok('reject weak refresh pepper', true);
}
echo "PASS: V5 access JWT crypto and opaque refresh primitives ($pass assertions)\n";
echo "MODE=OFFLINE_ONLY\nDATABASE_CONNECTION=NO\nPRODUCTION_WRITE=NO\n";
echo "ATOMIC_REFRESH_ROTATION=NOT_IMPLEMENTED\nSERVER_ASSURANCE_STORE=NOT_IMPLEMENTED\n";
