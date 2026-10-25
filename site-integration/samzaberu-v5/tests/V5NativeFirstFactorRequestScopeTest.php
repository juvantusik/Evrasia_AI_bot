<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/V5BitrixNativeFirstFactorComposition.php';

use Evrasia\Samzaberu\V5\V5BitrixNativeFirstFactorComposition as Composer;
use Evrasia\Samzaberu\V5\V5StagedHttpRequest;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('v5_scoped_first_factor_offline_only');
}

$passed = 0;
$check = static function (bool $yes, string $label) use (&$passed): void {
    if (!$yes) {
        throw new RuntimeException('V5_SCOPED_FIRST_FACTOR_' . $label);
    }
    ++$passed;
};

$device = 'sz_' . str_repeat('a', 64);
$body = json_encode([
    'login' => 'shape-only@example.invalid',
    'password' => 'never-verified-input',
    'device_id' => $device,
    'platform' => 'ios',
    'trust_token' => null,
], JSON_THROW_ON_ERROR);

$request = static function (
    string $raw,
    string $method = 'POST',
    string $uri = '/api/v5/auth/signin',
    bool $https = true,
    string $contentType = 'application/json',
    ?string $length = null
): V5StagedHttpRequest {
    return new V5StagedHttpRequest(
        $method, $uri, $https, $contentType, $raw,
        $length ?? (string)strlen($raw)
    );
};

$found = Composer::deviceFromScopedSignIn($request($body));
$check($found === ['device_id' => $device, 'platform' => 'ios'],
    'VALID_REQUEST_EXTRACTED');

foreach ([
    $request($body, 'GET'),
    $request($body, 'POST', '/api/v5/auth/signin/'),
    $request($body, 'POST', '/api/v5/auth/refresh'),
    $request($body, 'POST', '/api/v5/auth/signin?debug=1'),
    $request($body, 'POST', '/api/v5/auth/signin', false),
    $request($body, 'POST', '/api/v5/auth/signin', true, 'text/plain'),
    $request(''),
    $request('[]'),
    $request('{}'),
    $request('{'),
    $request(str_repeat('x', 8193)),
    new V5StagedHttpRequest('POST', '/api/v5/auth/signin', true,
        'application/json', $body, '1'),
] as $case) {
    $check(Composer::deviceFromScopedSignIn($case) === null,
        'NONCANONICAL_REQUEST_REJECTED');
}

foreach ([
    ['login' => '', 'password' => 'password', 'device_id' => $device, 'platform' => 'ios'],
    ['login' => 'test', 'password' => '', 'device_id' => $device, 'platform' => 'ios'],
    ['login' => 'test', 'password' => 'secret', 'device_id' => 'sz_' . str_repeat('b', 63), 'platform' => 'ios'],
    ['login' => 'test', 'password' => 'secret', 'device_id' => $device, 'platform' => 'web'],
    ['login' => 'test', 'password' => 'secret', 'device_id' => $device, 'platform' => 'ios', 'user_id' => 1],
    ['login' => 'test', 'password' => 'secret', 'device_id' => $device, 'platform' => 'ios', 'trust_token' => []],
    ['login' => ['not-a-string'], 'password' => 'secret', 'device_id' => $device, 'platform' => 'ios'],
] as $invalidBody) {
    $json = json_encode($invalidBody, JSON_THROW_ON_ERROR);
    $check(Composer::deviceFromScopedSignIn($request($json)) === null,
        'UNTRUSTED_IDENTITY_REJECTED');
}

echo "V5_SCOPED_FIRST_FACTOR_SHAPE=PASS\n";
echo "SCOPED_ASSERTIONS=$passed\n";
echo "PASSWORD_READ_FROM_DB=NO\n";
echo "PASSWORD_LOGGED=NO\n";
echo "SYNTHETIC_GUEST_ACCOUNTS=NO\n";
echo "BITRIX_BOOTSTRAP=NO\n";
echo "DATABASE_CONNECTION=NO\n";
echo "JWT_ISSUED=NO\n";
